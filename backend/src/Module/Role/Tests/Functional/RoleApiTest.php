<?php

declare(strict_types=1);

namespace App\Module\Role\Tests\Functional;

use App\Module\Identity\Entity\Membership;
use App\Module\Role\Entity\Role;
use App\Tests\Support\ApiTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/** A tenant's roles over HTTP - and that what they grant is what the voter enforces. */
final class RoleApiTest extends ApiTestCase
{
    protected function fixtures(): array
    {
        return [Role::class];
    }

    /** @return array<string, array<string, mixed>> key => role, read back over the API */
    private function roles(): array
    {
        $this->get('/api/roles');
        self::assertResponseIsSuccessful();

        return array_column($this->json()['items'], null, 'key');
    }

    public function testEveryTenantHasTheThreeBuiltInRolesBeforeSavingAny(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn(Membership::ROLE_MEMBER, 'member@example.test');
        self::assertArrayHasKey('roleName', $this->json()['memberships'][0]);
        self::assertNull($this->json()['memberships'][0]['roleName'], 'a built-in role is named by the UI, in the reader\'s language');

        $roles = $this->roles();

        self::assertSame(['owner', 'admin', 'member'], array_keys($roles));
        self::assertFalse($roles['owner']['editable']);
        self::assertContains('api_key.manage', $roles['owner']['permissions']);
        self::assertNotContains('api_key.manage', $roles['admin']['permissions']);
        self::assertContains('role.manage', $roles['admin']['permissions']);
        self::assertContains('example.view', $roles['member']['permissions']);
        self::assertNotContains('example.manage', $roles['member']['permissions']);
        self::assertSame(1, $roles['member']['holders']);
    }

    public function testATenantsOwnRoleGrantsWhatWasTickedAndTheVoterAgrees(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->post('/api/roles', ['name' => 'Front desk', 'permissions' => ['example.view', 'example.manage']]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('front-desk', $this->json()['key']);
        self::assertSame(['example.manage', 'example.view'], $this->json()['permissions']);

        $this->givenIAmSignedIn('front-desk', 'desk@example.test');
        self::assertSame(['example.manage', 'example.view'], $this->json()['permissions'] ?? null, 'the session says it');
        self::assertSame('Front desk', $this->json()['memberships'][0]['roleName'] ?? null, 'and names the role as the tenant does');

        $this->get('/api/projects');
        self::assertResponseIsSuccessful();
        $this->get('/api/units');
        self::assertResponseStatusCodeSame(403, 'not ticked: refused, though a plain member may');
    }

    public function testRestrictingTheMemberRoleAppliesOnTheNextRequest(): void
    {
        $this->givenATenant();
        $this->makeUser('member@example.test', Membership::ROLE_MEMBER);
        $this->givenIAmSignedIn();

        // Never saved: version 0, and this first save is what stores it.
        self::assertSame(0, $this->roles()['member']['version']);
        $this->patch('/api/roles/member', ['name' => null, 'permissions' => ['role.view']], '"0"');
        self::assertResponseIsSuccessful();
        self::assertSame(['role.view'], $this->json()['permissions']);

        $this->signInAs('member@example.test');
        $this->get('/api/units');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['role.view'], $this->roles()['member']['permissions']);
    }

    public function testASecondEditFromAStaleReadIsAConflict(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->patch('/api/roles/admin', ['name' => 'Manager', 'permissions' => ['example.view']], '"0"');
        self::assertResponseIsSuccessful();
        $this->patch('/api/roles/admin', ['name' => 'Boss', 'permissions' => ['example.view']], '"0"');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Manager', $this->roles()['admin']['name']);
    }

    public function testWhatNoRoleButTheOwnerMayHoldIsRefused(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->post('/api/roles', ['name' => 'Keys', 'permissions' => ['api_key.manage']]);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('api_key.manage', $this->json()['error']['message'] ?? json_encode($this->json()));
        $this->post('/api/roles', ['name' => 'Typo', 'permissions' => ['example.mange']]);
        self::assertResponseStatusCodeSame(400);

        $this->patch('/api/roles/owner', ['name' => null, 'permissions' => []]);
        self::assertResponseStatusCodeSame(404);
        self::assertNotContains('Keys', array_column($this->roles(), 'name'));
    }

    /** Managing roles must not be a way to every permission. */
    public function testNobodyGrantsWhatTheyDoNotHold(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();
        $this->post('/api/roles', ['name' => 'Role keeper', 'permissions' => ['role.view', 'role.manage']]);
        $keeper = $this->json();

        $this->givenIAmSignedIn('role-keeper', 'keeper@example.test');
        $this->patch('/api/roles/role-keeper', ['name' => 'Role keeper', 'permissions' => ['role.view', 'role.manage', 'example.manage']], '"' . $keeper['version'] . '"');
        self::assertResponseStatusCodeSame(403);
        $this->post('/api/roles', ['name' => 'Escape', 'permissions' => ['example.manage']]);
        self::assertResponseStatusCodeSame(403);

        // Taking away what they never had is no escalation.
        $this->patch('/api/roles/member', ['name' => null, 'permissions' => []], '"0"');
        self::assertResponseIsSuccessful();
        self::assertSame(['role.manage', 'role.view'], $this->roles()['role-keeper']['permissions']);
    }

    public function testARoleSomeoneHoldsIsNotDeletedAndABuiltInOneNever(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();
        $this->post('/api/roles', ['name' => 'Auditor', 'permissions' => ['audit.view']]);
        $this->makeUser('auditor@example.test', 'auditor');

        $this->delete('/api/roles/auditor');
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('1 member', $this->json()['error']['message'] ?? json_encode($this->json()));

        $this->delete('/api/roles/member');
        self::assertResponseStatusCodeSame(404);

        $this->post('/api/roles', ['name' => 'Spare', 'permissions' => []]);
        $this->delete('/api/roles/spare');
        self::assertResponseStatusCodeSame(204);
        self::assertSame(['owner', 'admin', 'member', 'auditor'], array_keys($this->roles()));
    }

    public function testAPlainMemberSeesTheRolesButChangesNone(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn(Membership::ROLE_MEMBER, 'member@example.test');

        $this->post('/api/roles', ['name' => 'Mine', 'permissions' => []]);
        self::assertResponseStatusCodeSame(403);
        $this->get('/api/roles/permissions');
        self::assertResponseStatusCodeSame(403);
        self::assertCount(3, $this->roles());
    }

    public function testTheCatalogueGroupsWhatARoleMayHoldByModuleInTheReadersLanguage(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->get('/api/roles/permissions');
        self::assertResponseIsSuccessful();
        $groups = array_column($this->json()['groups'], null, 'module');

        // In whatever language the project serves - but translated, never the key.
        $translator = self::getContainer()->get('translator');
        \assert($translator instanceof TranslatorInterface);
        self::assertSame($translator->trans('example.title'), $groups['Example']['label']);
        self::assertNotSame('example.title', $groups['Example']['label']);
        self::assertContains(['name' => 'example.manage', 'label' => $translator->trans('permission.example.manage')], $groups['Example']['permissions']);
        self::assertNotContains('api_key.manage', array_column($groups['ApiKey']['permissions'] ?? [], 'name'));
    }
}
