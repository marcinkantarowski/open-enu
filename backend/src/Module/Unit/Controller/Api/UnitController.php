<?php

declare(strict_types=1);

namespace App\Module\Unit\Controller\Api;

use App\Module\Unit\Command\ArchiveUnit;
use App\Module\Unit\Command\CreateUnit;
use App\Module\Unit\Command\MoveUnit;
use App\Module\Unit\Command\RenameUnit;
use App\Module\Unit\Entity\Unit;
use App\Module\Unit\Repository\UnitRepository;
use OpenEnu\Kernel\Command\CommandBusInterface;
use OpenEnu\Kernel\Doctrine\OptimisticLock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Thin by rule: validate, delegate, serialize. */
final readonly class UnitController
{
    public function __construct(
        private UnitRepository $units,
        private CommandBusInterface $commands,
        private OptimisticLock $lock,
    ) {
    }

    /** The whole tree, flat, parents before children; the client nests it by `parentId`. */
    #[Route('/api/units', name: 'unit_index', methods: ['GET'])]
    #[IsGranted('unit.view')]
    public function index(): JsonResponse
    {
        return new JsonResponse(['items' => array_map(static fn (Unit $u): array => $u->toArray(), $this->units->tree())]);
    }

    #[Route('/api/units', name: 'unit_create', methods: ['POST'])]
    #[IsGranted('unit.manage')]
    public function create(Request $request): JsonResponse
    {
        $body = $this->body($request);

        return new JsonResponse($this->commands->dispatch(new CreateUnit(
            parentId: $this->optionalId($body, 'parentId'),
            name: $this->text($body, 'name', 200),
            kind: $this->text($body, 'kind', 40),
        )), Response::HTTP_CREATED);
    }

    #[Route('/api/units/{id}', name: 'unit_rename', methods: ['PATCH'])]
    #[IsGranted('unit.manage')]
    public function rename(string $id, Request $request): JsonResponse
    {
        $unit = $this->find($id);
        $body = $this->body($request);

        $this->lock->assertCurrent($unit, $request, $body, static fn (): array => $unit->toArray());

        return new JsonResponse($this->commands->dispatch(new RenameUnit(
            $unit->id(),
            $this->text($body, 'name', 200),
            $this->text($body, 'kind', 40),
        )));
    }

    #[Route('/api/units/{id}/move', name: 'unit_move', methods: ['POST'])]
    #[IsGranted('unit.manage')]
    public function move(string $id, Request $request): JsonResponse
    {
        return new JsonResponse($this->commands->dispatch(new MoveUnit(
            $this->find($id)->id(),
            $this->optionalId($this->body($request), 'parentId'),
        )));
    }

    #[Route('/api/units/{id}/archive', name: 'unit_archive', methods: ['POST'])]
    #[IsGranted('unit.manage')]
    public function archive(string $id): JsonResponse
    {
        return new JsonResponse($this->commands->dispatch(new ArchiveUnit($this->find($id)->id())));
    }

    private function find(string $id): Unit
    {
        return $this->units->get($id) ?? throw new NotFoundHttpException('No such unit.');
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $decoded = json_decode($request->getContent() ?: '{}', true);

        return \is_array($decoded) ? $decoded : throw new BadRequestHttpException('Expected a JSON object.');
    }

    /** @param array<string, mixed> $body */
    private function text(array $body, string $field, int $max): string
    {
        $value = $body[$field] ?? null;

        return \is_string($value) && trim($value) !== '' && mb_strlen(trim($value)) <= $max
            ? trim($value)
            : throw new BadRequestHttpException(sprintf('"%s" must be a non-empty string of at most %d characters.', $field, $max));
    }

    /** @param array<string, mixed> $body */
    private function optionalId(array $body, string $field): ?string
    {
        $value = $body[$field] ?? null;

        return $value === null || \is_string($value) ? $value : throw new BadRequestHttpException(sprintf('"%s" must be a unit id or null.', $field));
    }
}
