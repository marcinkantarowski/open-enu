<?php

declare(strict_types=1);

namespace App\Module\Member\Controller\Api;

use App\Module\Identity\Contract\MembershipsInterface;
use App\Module\Member\Command\AcceptInvitation;
use App\Module\Member\Command\ChangeMemberAccess;
use App\Module\Member\Command\InviteMember;
use App\Module\Member\Command\ReactivateMember;
use App\Module\Member\Command\SuspendMember;
use OpenEnu\Kernel\Command\CommandBusInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A tenant's people. Thin by rule: validate, delegate, serialize - who may do
 * what to whom is `Service/AccessGuard`, called from the handlers.
 *
 * Changing access is a POST action, like moving a unit, carrying the version
 * the editor read: two administrators changing one person get a 409, not a
 * silent last-write-wins.
 */
final readonly class MemberController
{
    public function __construct(
        private MembershipsInterface $memberships,
        private CommandBusInterface $commands,
        private ScopeContext $scope,
        #[Target('authLimiter')] private RateLimiterFactoryInterface $authLimiter,
    ) {
    }

    #[Route('/api/members', name: 'member_index', methods: ['GET'])]
    #[IsGranted('member.view')]
    public function index(): JsonResponse
    {
        $tenantId = $this->scope->tenantId() ?? throw new AccessDeniedHttpException('No tenant in scope.');

        return new JsonResponse(['items' => $this->memberships->inTenant($tenantId)]);
    }

    #[Route('/api/members', name: 'member_invite', methods: ['POST'])]
    #[IsGranted('member.invite')]
    public function invite(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $email = $body['email'] ?? null;
        if (!\is_string($email) || filter_var(trim($email), \FILTER_VALIDATE_EMAIL) === false) {
            throw new BadRequestHttpException('"email" must be an e-mail address.');
        }

        return new JsonResponse($this->commands->dispatch(new InviteMember(
            mb_strtolower(trim($email)),
            $this->role($body),
            $this->unitIds($body),
        )), Response::HTTP_CREATED);
    }

    #[Route('/api/members/{id}/access', name: 'member_access', methods: ['POST'])]
    #[IsGranted('member.manage')]
    public function access(string $id, Request $request): JsonResponse
    {
        $body = $this->body($request);
        $version = $body['version'] ?? trim((string) $request->headers->get('If-Match'), '"W/') ?: null;

        return new JsonResponse($this->commands->dispatch(new ChangeMemberAccess(
            $id,
            $this->role($body),
            $this->unitIds($body),
            is_numeric($version) ? (int) $version : null,
        )));
    }

    #[Route('/api/members/{id}/suspend', name: 'member_suspend', methods: ['POST'])]
    #[IsGranted('member.manage')]
    public function suspend(string $id): JsonResponse
    {
        return new JsonResponse($this->commands->dispatch(new SuspendMember($id)));
    }

    #[Route('/api/members/{id}/reactivate', name: 'member_reactivate', methods: ['POST'])]
    #[IsGranted('member.manage')]
    public function reactivate(string $id): JsonResponse
    {
        return new JsonResponse($this->commands->dispatch(new ReactivateMember($id)));
    }

    /**
     * What a link is for, before it is used: whose tenant, which address, and
     * whether the page must ask for a password. Public - the token is the
     * credential - and rate-limited like every other public auth endpoint.
     */
    #[Route('/api/auth/invitation', name: 'member_invitation_show', methods: ['GET'])]
    public function showInvitation(Request $request): JsonResponse
    {
        $this->limit($request);
        $token = $request->query->get('token');

        $invitation = \is_string($token) && $token !== '' ? $this->memberships->describeInvitation($token) : null;

        return new JsonResponse($invitation ?? throw new NotFoundHttpException('This invitation is invalid, has expired, or was withdrawn.'));
    }

    #[Route('/api/auth/invitation', name: 'member_invitation_accept', methods: ['POST'])]
    public function acceptInvitation(Request $request): JsonResponse
    {
        $this->limit($request);
        $body = $this->body($request);

        $token = $body['token'] ?? null;
        if (!\is_string($token) || $token === '') {
            throw new BadRequestHttpException('"token" is required.');
        }

        $password = $body['password'] ?? null;
        if ($password !== null && (!\is_string($password) || mb_strlen($password) < 12)) {
            throw new BadRequestHttpException('The password must be at least 12 characters.');
        }

        $name = $body['displayName'] ?? null;

        return new JsonResponse($this->commands->dispatch(new AcceptInvitation(
            $token,
            $password,
            \is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 120) : null,
        )));
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $decoded = json_decode($request->getContent() ?: '{}', true);

        return \is_array($decoded) ? $decoded : throw new BadRequestHttpException('Expected a JSON object.');
    }

    /** @param array<string, mixed> $body */
    private function role(array $body): string
    {
        $role = $body['role'] ?? null;

        return \is_string($role) && $role !== '' ? $role : throw new BadRequestHttpException('"role" is required.');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>|null null: the whole tenant
     */
    private function unitIds(array $body): ?array
    {
        $value = $body['unitIds'] ?? null;
        if ($value === null) {
            return null;
        }

        if (!\is_array($value) || array_filter($value, static fn (mixed $id): bool => !\is_string($id)) !== []) {
            throw new BadRequestHttpException('"unitIds" must be a list of unit ids, or null for the whole tenant.');
        }

        /** @var list<string> */
        return array_values(array_unique($value));
    }

    private function limit(Request $request): void
    {
        $limit = $this->authLimiter->create($request->getClientIp() ?? 'unknown')->consume();

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                'Too many attempts. Try again shortly.',
            );
        }
    }
}
