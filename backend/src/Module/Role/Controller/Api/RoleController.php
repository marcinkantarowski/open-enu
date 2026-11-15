<?php

declare(strict_types=1);

namespace App\Module\Role\Controller\Api;

use App\Module\Role\Contract\RoleHoldersInterface;
use App\Module\Role\Contract\PermissionsInterface;
use App\Module\Role\Command\CreateRole;
use App\Module\Role\Command\DeleteRole;
use App\Module\Role\Command\UpdateRole;
use App\Module\Role\Entity\Role;
use App\Module\Role\Repository\RoleRepository;
use App\Module\Role\Service\PermissionResolver;
use OpenEnu\Kernel\Command\CommandBusInterface;
use OpenEnu\Kernel\Doctrine\OptimisticLock;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Module\ModuleRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The tenant's roles (ADR-0025). Thin by rule: validate, delegate, serialize.
 *
 * Roles are addressed by key, not id: `admin` and `member` exist in every
 * tenant before anyone saves them, and the first save is what stores them.
 */
final readonly class RoleController
{
    public function __construct(
        private RoleRepository $roles,
        private RoleHoldersInterface $holders,
        private PermissionResolver $permissions,
        private ModuleRegistry $modules,
        private ScopeContext $scope,
        private CommandBusInterface $commands,
        private OptimisticLock $lock,
        private AuthorizationCheckerInterface $auth,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Every role this tenant has: owner, the two built-in ones, then its own -
     * each with what it grants RIGHT NOW, which for a role never saved is the
     * built-in rule.
     */
    #[Route('/api/roles', name: 'role_index', methods: ['GET'])]
    #[IsGranted('role.view')]
    public function index(): JsonResponse
    {
        $tenantId = $this->tenantId();
        $own = array_filter($this->roles->inTenant(), static fn (Role $r): bool => !$r->isBuiltIn());

        $items = [[
            'id' => null,
            'key' => PermissionsInterface::OWNER,
            'name' => null,
            'builtIn' => true,
            'version' => null,
        ]];
        foreach ([PermissionsInterface::ADMIN, PermissionsInterface::MEMBER] as $key) {
            $items[] = ($this->roles->editable($key, $tenantId) ?? throw new \LogicException($key))->toArray();
        }
        foreach ($own as $role) {
            $items[] = $role->toArray();
        }

        return new JsonResponse(['items' => array_map(fn (array $item): array => [
            ...$item,
            'permissions' => $this->permissions->forRole($tenantId, $item['key']),
            'editable' => $item['key'] !== PermissionsInterface::OWNER,
            'holders' => $this->holders->holding($tenantId, $item['key']),
        ], $items)]);
    }

    /** What a role may hold, grouped by the module that declares it, in the reader's language. */
    #[Route('/api/roles/permissions', name: 'role_permissions', methods: ['GET'])]
    #[IsGranted('role.manage')]
    public function catalogue(): JsonResponse
    {
        $groups = [];
        foreach ($this->permissions->assignable() as $permission) {
            $module = $this->modules->ownerOf($permission) ?? 'unknown';
            $prefix = explode('.', $permission)[0];
            $groups[$module] ??= ['module' => $module, 'label' => $this->translator->trans($prefix . '.title'), 'permissions' => []];
            $groups[$module]['permissions'][] = ['name' => $permission, 'label' => $this->translator->trans('permission.' . $permission)];
        }

        usort($groups, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return new JsonResponse(['groups' => $groups]);
    }

    #[Route('/api/roles', name: 'role_create', methods: ['POST'])]
    #[IsGranted('role.manage')]
    public function create(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $permissions = $this->permissionList($body);
        $this->permissions->assertAssignable($permissions);
        $this->assertCallerHolds($permissions);

        return new JsonResponse($this->commands->dispatch(new CreateRole(
            $this->name($body) ?? throw new BadRequestHttpException('"name" is required.'),
            $permissions,
        )), Response::HTTP_CREATED);
    }

    #[Route('/api/roles/{key}', name: 'role_update', methods: ['PATCH'])]
    #[IsGranted('role.manage')]
    public function update(string $key, Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $role = $this->roles->editable($key, $tenantId) ?? throw new NotFoundHttpException('No such role, or one that cannot be edited.');
        $body = $this->body($request);

        $this->lock->assertCurrent($role, $request, $body, static fn (): array => $role->toArray());

        $permissions = $this->permissionList($body);
        $this->permissions->assertAssignable($permissions);
        // Only what is being ADDED must be the caller's own: removing a grant
        // they never had is no escalation, and must stay possible.
        $this->assertCallerHolds(array_values(array_diff($permissions, $this->permissions->forRole($tenantId, $key))));

        return new JsonResponse($this->commands->dispatch(new UpdateRole($key, $this->name($body), $permissions)));
    }

    #[Route('/api/roles/{key}', name: 'role_delete', methods: ['DELETE'])]
    #[IsGranted('role.manage')]
    public function delete(string $key): Response
    {
        $this->commands->dispatch(new DeleteRole($key));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * No one hands out what they do not hold. Without this, managing roles IS
     * every permission: tick it on your own role and reload.
     *
     * @param list<string> $permissions
     */
    private function assertCallerHolds(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (!$this->auth->isGranted($permission)) {
                throw new AccessDeniedHttpException(sprintf('You cannot grant "%s": you do not hold it yourself.', $permission));
            }
        }
    }

    private function tenantId(): string
    {
        return $this->scope->tenantId() ?? throw new AccessDeniedHttpException('No tenant in scope.');
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $decoded = json_decode($request->getContent() ?: '{}', true);

        return \is_array($decoded) ? $decoded : throw new BadRequestHttpException('Expected a JSON object.');
    }

    /** @param array<string, mixed> $body */
    private function name(array $body): ?string
    {
        $value = $body['name'] ?? null;
        if ($value === null) {
            return null;
        }

        return \is_string($value) && trim($value) !== '' && mb_strlen(trim($value)) <= 80
            ? trim($value)
            : throw new BadRequestHttpException('"name" must be a non-empty string of at most 80 characters, or null.');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function permissionList(array $body): array
    {
        $value = $body['permissions'] ?? null;
        if (!\is_array($value) || array_filter($value, static fn (mixed $p): bool => !\is_string($p)) !== []) {
            throw new BadRequestHttpException('"permissions" must be a list of permission names.');
        }

        /** @var list<string> */
        return array_values(array_unique($value));
    }
}
