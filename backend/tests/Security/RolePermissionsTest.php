<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Identity\Entity\Membership;
use App\Module\Identity\Service\PermissionResolver;
use OpenEnu\Kernel\Module\ModuleRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What each role may do. Here and not in a module: which permissions a plain
 * member holds is a property of the system, and every module's endpoints are
 * only as closed as this rule.
 */
#[CoversClass(PermissionResolver::class)]
final class RolePermissionsTest extends TestCase
{
    private PermissionResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PermissionResolver(new ModuleRegistry([], [
            'desk.view' => 'Desk',
            'desk.use' => 'Desk',
            'desk.manage' => 'Desk',
            // A name that merely contains the words is neither.
            'desk.user.manage' => 'Desk',
            'desk.reuse' => 'Desk',
            'api_key.manage' => 'ApiKey',
        ]));
    }

    public function testAMemberReadsAndDoesTheEverydayWorkAndNothingElse(): void
    {
        self::assertSame(['desk.use', 'desk.view'], $this->resolver->forRole(Membership::ROLE_MEMBER));
    }

    public function testAnAdminHoldsEverythingButWhatIsTheOwnersAlone(): void
    {
        self::assertTrue($this->resolver->allows(Membership::ROLE_ADMIN, 'desk.manage'));
        self::assertTrue($this->resolver->allows(Membership::ROLE_ADMIN, 'desk.use'));
        self::assertFalse($this->resolver->allows(Membership::ROLE_ADMIN, 'api_key.manage'));
        self::assertTrue($this->resolver->allows(Membership::ROLE_OWNER, 'api_key.manage'));
    }

    public function testAnUndeclaredPermissionIsRefusedToEveryone(): void
    {
        self::assertFalse($this->resolver->allows(Membership::ROLE_OWNER, 'desk.usee'));
        self::assertFalse($this->resolver->allows(Membership::ROLE_MEMBER, 'other.use'));
        self::assertFalse($this->resolver->allows('stranger', 'desk.view'));
    }
}
