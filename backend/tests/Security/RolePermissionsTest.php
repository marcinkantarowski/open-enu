<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Role\Contract\PermissionsInterface as Role;
use App\Module\Role\Service\PermissionResolver;
use App\Module\Role\Service\RoleGrantSource;
use OpenEnu\Kernel\Module\ModuleRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What each role may do (ADR-0025). Here and not in a module: which permissions
 * a role holds is a property of the system, and every module's endpoints are
 * only as closed as this rule.
 */
#[CoversClass(PermissionResolver::class)]
final class RolePermissionsTest extends TestCase
{
    private const string TENANT = 't1';

    /** @var \ArrayObject<string, array{permissions: list<string>, known: list<string>, template: ?string}> */
    private \ArrayObject $stored;

    private PermissionResolver $resolver;

    protected function setUp(): void
    {
        $this->stored = new \ArrayObject();
        $stored = $this->stored;

        $this->resolver = new PermissionResolver(new ModuleRegistry([], [
            'desk.view' => 'Desk',
            'desk.use' => 'Desk',
            'desk.manage' => 'Desk',
            // A name that merely contains the words is neither.
            'desk.user.manage' => 'Desk',
            'desk.reuse' => 'Desk',
            'api_key.manage' => 'ApiKey',
        ]), new class ($stored) implements RoleGrantSource {
            /** @param \ArrayObject<string, array{permissions: list<string>, known: list<string>, template: ?string}> $stored */
            public function __construct(private \ArrayObject $stored)
            {
            }

            public function grantsFor(string $tenantId): array
            {
                return $this->stored->getArrayCopy();
            }
        });
    }

    public function testAMemberReadsAndDoesTheEverydayWorkAndNothingElse(): void
    {
        self::assertSame(['desk.use', 'desk.view'], $this->resolver->forRole(self::TENANT, Role::MEMBER));
    }

    public function testAnAdminHoldsEverythingButWhatIsTheOwnersAlone(): void
    {
        self::assertTrue($this->resolver->allows(self::TENANT, Role::ADMIN, 'desk.manage'));
        self::assertFalse($this->resolver->allows(self::TENANT, Role::ADMIN, 'api_key.manage'));
        self::assertTrue($this->resolver->allows(self::TENANT, Role::OWNER, 'api_key.manage'));
    }

    public function testAnUndeclaredPermissionIsRefusedToEveryone(): void
    {
        self::assertFalse($this->resolver->allows(self::TENANT, Role::OWNER, 'desk.usee'));
        self::assertFalse($this->resolver->allows(self::TENANT, Role::MEMBER, 'other.use'));
        self::assertFalse($this->resolver->allows(self::TENANT, 'stranger', 'desk.view'));
    }

    public function testATenantsOwnRoleGrantsExactlyWhatWasTicked(): void
    {
        $this->stored['desk-clerk'] = ['permissions' => ['desk.manage'], 'known' => ['desk.manage', 'desk.view'], 'template' => null];

        self::assertSame(['desk.manage'], $this->resolver->forRole(self::TENANT, 'desk-clerk'));
    }

    public function testAnOwnerOnlyPermissionStoredOnAnotherRoleIsStillRefused(): void
    {
        $this->stored['desk-clerk'] = ['permissions' => ['api_key.manage'], 'known' => [], 'template' => null];

        self::assertFalse($this->resolver->allows(self::TENANT, 'desk-clerk', 'api_key.manage'));
    }

    public function testAnAdminATenantRestrictedStaysRestricted(): void
    {
        $this->stored[Role::ADMIN] = [
            'permissions' => ['desk.view'],
            'known' => ['desk.view', 'desk.use', 'desk.manage', 'desk.user.manage', 'desk.reuse', 'api_key.manage'],
            'template' => Role::ADMIN,
        ];

        self::assertSame(['desk.view'], $this->resolver->forRole(self::TENANT, Role::ADMIN));
    }

    /** A module added after the role was saved: its template decides, not silence. */
    public function testAPermissionDeclaredAfterTheRoleWasSavedFollowsTheTemplate(): void
    {
        $known = ['desk.view', 'desk.manage', 'desk.user.manage', 'desk.reuse', 'api_key.manage'];
        $this->stored[Role::ADMIN] = ['permissions' => ['desk.view'], 'known' => $known, 'template' => Role::ADMIN];
        $this->stored['desk-clerk'] = ['permissions' => ['desk.view'], 'known' => $known, 'template' => null];

        self::assertTrue($this->resolver->allows(self::TENANT, Role::ADMIN, 'desk.use'), 'new to the admin: granted, as the template would');
        self::assertFalse($this->resolver->allows(self::TENANT, 'desk-clerk', 'desk.use'), 'new to a tenant\'s own role: refused');
    }

    public function testTheGrantsAreReadOncePerRequestAndForgottenOnAChange(): void
    {
        self::assertTrue($this->resolver->allows(self::TENANT, Role::ADMIN, 'desk.manage'));

        $this->stored[Role::ADMIN] = ['permissions' => [], 'known' => ['desk.manage'], 'template' => Role::ADMIN];
        self::assertTrue($this->resolver->allows(self::TENANT, Role::ADMIN, 'desk.manage'), 'still the grants read at the start');

        $this->resolver->forget(self::TENANT);
        self::assertFalse($this->resolver->allows(self::TENANT, Role::ADMIN, 'desk.manage'));
    }
}
