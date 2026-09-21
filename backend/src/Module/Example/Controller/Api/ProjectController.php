<?php

declare(strict_types=1);

namespace App\Module\Example\Controller\Api;

use App\Module\Example\Command\ArchiveProjects;
use App\Module\Example\Command\CreateProject;
use App\Module\Example\Command\RenameProject;
use App\Module\Example\Entity\Project;
use App\Module\Example\Repository\ProjectRepository;
use App\Module\Example\Security\Voter\ProjectVoter;
use OpenEnu\Kernel\Attribute\DeniedUnderImpersonation;
use OpenEnu\Kernel\Command\CommandBusInterface;
use OpenEnu\Kernel\Doctrine\OptimisticLock;
use OpenEnu\Kernel\Dto\ListResponse;
use OpenEnu\Kernel\Dto\PaginationRequest;
use OpenEnu\Kernel\Flags\Flag;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Thin by rule: validate, delegate, serialize.
 *
 * Note what is NOT injected - no EntityManager (ThinControllerRule) - and what
 * is never called: `flush()` (PersistenceBoundaryRule). Every write goes through
 * the command bus, which is what audits it.
 */
final readonly class ProjectController
{
    public function __construct(
        private ProjectRepository $projects,
        private CommandBusInterface $commands,
        private OptimisticLock $lock,
        private Security $security,
    ) {
    }

    #[Route('/api/projects', name: 'example_project_index', methods: ['GET'])]
    #[IsGranted('example.view')]
    public function index(Request $request): JsonResponse
    {
        $pagination = PaginationRequest::fromRequest($request, ['name', 'createdAt']);

        return new JsonResponse(ListResponse::of(
            array_map(
                static fn (Project $p): array => $p->toArray(),
                $this->projects->page($pagination->offset(), $pagination->size),
            ),
            $this->projects->total(),
            $pagination,
        )->jsonSerialize());
    }

    #[Route('/api/projects', name: 'example_project_create', methods: ['POST'])]
    #[IsGranted('example.manage')]
    public function create(Request $request): JsonResponse
    {
        $body = $this->body($request);

        return new JsonResponse(
            $this->commands->dispatch(new CreateProject(
                name: $this->requireName($body),
                description: \is_string($body['description'] ?? null) ? $body['description'] : null,
                clientReference: \is_string($body['clientReference'] ?? null) ? $body['clientReference'] : null,
                attributes: \is_array($body['attributes'] ?? null) ? $body['attributes'] : [],
            )),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/api/projects/{id}', name: 'example_project_show', methods: ['GET'])]
    #[IsGranted('example.view')]
    public function show(string $id): JsonResponse
    {
        $project = $this->find($id);

        return new JsonResponse(
            // The encrypted column appears here and not in the list: what leaves
            // the server is decided per endpoint, not by the entity.
            [...$project->toArray(), 'clientReference' => $project->clientReference()],
            // The version the client now holds. It comes back on the next write,
            // and a mismatch is what makes a concurrent edit visible.
            headers: ['ETag' => '"' . $project->version() . '"'],
        );
    }

    #[Route('/api/projects/{id}', name: 'example_project_rename', methods: ['PATCH'])]
    #[IsGranted('example.manage')]
    // Support may look; support may not rename things while wearing someone
    // else's face (ADR-0008).
    #[DeniedUnderImpersonation(because: 'Renaming is not available while viewing as another user.')]
    public function rename(string $id, Request $request): JsonResponse
    {
        $project = $this->find($id);

        // Permission says what this ROLE may do; the voter says whether THIS row
        // may be touched. Both, because they answer different questions.
        if (!$this->security->isGranted(ProjectVoter::EDIT, $project)) {
            throw new AccessDeniedHttpException('That project cannot be edited.');
        }

        $body = $this->body($request);

        // Refuses a write built on a stale read. Throws ConflictException, which
        // the API error handler renders as 409 with both versions and the saved
        // record, so the client can offer reload / overwrite instead of "your
        // work is gone".
        $this->lock->assertCurrent($project, $request, $body, static fn (): array => $project->toArray());

        return new JsonResponse($this->commands->dispatch(new RenameProject($id, $this->requireName($body))));
    }

    /**
     * The flag-guarded route.
     *
     * Disabled ⇒ 404, not 403: "not built yet" and "turned off after an
     * incident" should be indistinguishable from outside. 202 rather than 200 -
     * the work has been accepted, not done; `jobId` is what the browser watches.
     */
    #[Route('/api/projects/archive', name: 'example_project_archive', methods: ['POST'])]
    #[IsGranted('example.manage')]
    #[Flag('example.archive')]
    #[DeniedUnderImpersonation(because: 'Bulk archiving is not available while viewing as another user.')]
    public function archive(): JsonResponse
    {
        return new JsonResponse($this->commands->dispatch(new ArchiveProjects()), Response::HTTP_ACCEPTED);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function find(string $id): Project
    {
        // Another tenant's project is NOT FOUND rather than forbidden: the scope
        // filter removed it from the query, so this cannot leak its existence.
        return $this->projects->get($id) ?? throw new NotFoundHttpException('No such project.');
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $decoded = json_decode($request->getContent() ?: '{}', true);

        return \is_array($decoded) ? $decoded : throw new BadRequestHttpException('Expected a JSON object.');
    }

    /** @param array<string, mixed> $body */
    private function requireName(array $body): string
    {
        $name = $body['name'] ?? null;

        return \is_string($name) && trim($name) !== ''
            ? trim($name)
            : throw new BadRequestHttpException('A non-empty "name" is required.');
    }
}
