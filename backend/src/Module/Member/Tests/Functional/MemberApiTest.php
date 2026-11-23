<?php

declare(strict_types=1);

namespace App\Module\Member\Tests\Functional;

use App\Module\Example\Entity\Project;
use App\Module\Identity\Entity\Membership;
use App\Module\Role\Entity\Role;
use App\Module\Unit\Entity\Unit;
use App\Tests\Support\ApiTestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/** A tenant's people over HTTP: in by invitation, out by suspension, never past the one deciding. */
final class MemberApiTest extends ApiTestCase
{
    protected function fixtures(): array
    {
        return [Project::class, Unit::class, Role::class];
    }

    /** @return array<string, array<string, mixed>> email => member */
    private function members(): array
    {
        $this->get('/api/members');
        self::assertResponseIsSuccessful();

        return array_column($this->json()['items'], null, 'email');
    }

    private function invitationToken(): string
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        // The rendered body, as the invitee receives it.
        $url = html_entity_decode((string) $email->getHtmlBody());
        self::assertSame(1, preg_match('/[?&]token=([A-Za-z0-9_-]+)/', $url, $m), 'the mail carries the link: ' . $url);

        return $m[1];
    }

    public function testSomeoneInvitedHasNothingUntilTheyAcceptAndThenSignsIn(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->post('/api/members', ['email' => 'New.Person@Example.test', 'role' => 'member', 'unitIds' => null]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('invited', $this->json()['status']);
        self::assertSame('new.person@example.test', $this->json()['email']);
        $token = $this->invitationToken();

        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->get('/api/auth/invitation?token=' . $token);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['needsPassword']);
        self::assertSame('new.person@example.test', $this->json()['email']);

        $this->post('/api/auth/invitation', ['token' => $token]);
        self::assertResponseStatusCodeSame(400, 'a new account cannot join without a password');

        $this->post('/api/auth/invitation', ['token' => $token, 'password' => self::PASSWORD, 'displayName' => 'New Person']);
        self::assertResponseIsSuccessful();
        self::assertSame($this->tenantId, $this->json()['tenantId']);

        $this->post('/api/auth/invitation', ['token' => $token, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(400, 'a link works once');

        $this->signInAs('new.person@example.test');
        $this->get('/api/projects');
        self::assertResponseIsSuccessful();
    }

    public function testAnExistingAccountJoinsWithoutAPasswordAndKeepsItsOwn(): void
    {
        $other = $this->givenATenant('elsewhere');
        $this->givenATenant();
        $this->makeUser('known@example.test', Membership::ROLE_OWNER, $other);
        $this->givenIAmSignedIn();

        $this->post('/api/members', ['email' => 'known@example.test', 'role' => 'admin']);
        $token = $this->invitationToken();

        $this->get('/api/auth/invitation?token=' . $token);
        self::assertFalse($this->json()['needsPassword']);
        $this->post('/api/auth/invitation', ['token' => $token, 'password' => 'not-the-real-one-at-all']);
        self::assertResponseIsSuccessful();

        $this->signInAs('known@example.test');
        self::assertCount(2, $this->json()['memberships']);
    }

    public function testInvitingSomeoneAlreadyHereIsAConflict(): void
    {
        $this->givenATenant();
        $this->makeUser('here@example.test', Membership::ROLE_MEMBER);
        $this->givenIAmSignedIn();

        $this->post('/api/members', ['email' => 'here@example.test', 'role' => 'member']);
        self::assertResponseStatusCodeSame(409);
        self::assertCount(2, $this->members());
    }

    public function testRoleAndUnitsChangeTogetherAndAStaleEditIsAConflict(): void
    {
        $this->givenATenant();
        $this->makeUser('clerk@example.test', Membership::ROLE_MEMBER);
        $this->givenIAmSignedIn();
        $this->post('/api/units', ['name' => 'North', 'kind' => 'site', 'parentId' => null]);
        $north = $this->json()['id'];
        $clerk = $this->members()['clerk@example.test'];

        $this->post('/api/members/' . $clerk['id'] . '/access', ['role' => 'admin', 'unitIds' => [$north], 'version' => $clerk['version']]);
        self::assertResponseIsSuccessful();
        self::assertSame('admin', $this->json()['role']);
        self::assertSame([$north], $this->json()['unitIds']);

        $this->post('/api/members/' . $clerk['id'] . '/access', ['role' => 'member', 'unitIds' => null, 'version' => $clerk['version']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('admin', $this->members()['clerk@example.test']['role']);

        $this->post('/api/members/' . $clerk['id'] . '/access', ['role' => 'nobody', 'unitIds' => null]);
        self::assertResponseStatusCodeSame(400);
        $this->post('/api/members/' . $clerk['id'] . '/access', ['role' => 'member', 'unitIds' => ['01900000-0000-7000-8000-000000000000']]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testATenantNeverLosesItsLastActiveOwner(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();
        $me = $this->members()['owner@example.test'];

        $this->post('/api/members/' . $me['id'] . '/suspend');
        self::assertResponseStatusCodeSame(409);
        $this->post('/api/members/' . $me['id'] . '/access', ['role' => 'admin', 'unitIds' => null]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('owner', $this->members()['owner@example.test']['role']);

        $this->makeUser('second@example.test', Membership::ROLE_OWNER);
        $this->post('/api/members/' . $me['id'] . '/access', ['role' => 'admin', 'unitIds' => null]);
        self::assertResponseIsSuccessful();
    }

    /** Nobody hands out more than they hold - a role, or a reach. */
    public function testAnAdminCannotMakeAnOwnerNorTouchOne(): void
    {
        $this->givenATenant();
        $this->makeUser('boss@example.test', Membership::ROLE_OWNER);
        $this->makeUser('plain@example.test', Membership::ROLE_MEMBER);
        $this->givenIAmSignedIn(Membership::ROLE_ADMIN, 'admin@example.test');
        $members = $this->members();
        // The list says so before anyone tries, so the page shows no pencil it would refuse.
        self::assertFalse($members['boss@example.test']['manageable']);
        self::assertTrue($members['plain@example.test']['manageable']);

        $this->post('/api/members', ['email' => 'pal@example.test', 'role' => 'owner']);
        self::assertResponseStatusCodeSame(403);
        $this->post('/api/members/' . $members['plain@example.test']['id'] . '/access', ['role' => 'owner', 'unitIds' => null]);
        self::assertResponseStatusCodeSame(403);
        $this->post('/api/members/' . $members['boss@example.test']['id'] . '/suspend');
        self::assertResponseStatusCodeSame(403);

        $this->post('/api/members/' . $members['plain@example.test']['id'] . '/suspend');
        self::assertResponseIsSuccessful();
        self::assertSame('suspended', $this->members()['plain@example.test']['status']);
    }

    public function testSomeoneNarrowedToAUnitGivesOnlyThatUnitAndItsChildren(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();
        $this->post('/api/units', ['name' => 'North', 'kind' => 'site', 'parentId' => null]);
        $north = $this->json()['id'];
        $this->post('/api/units', ['name' => 'South', 'kind' => 'site', 'parentId' => null]);
        $south = $this->json()['id'];

        $this->givenIAmSignedIn(Membership::ROLE_ADMIN, 'north@example.test');
        $mine = $this->members()['north@example.test'];
        $this->signInAs('owner@example.test');
        $this->post('/api/members/' . $mine['id'] . '/access', ['role' => 'admin', 'unitIds' => [$north]]);

        $this->signInAs('north@example.test');
        $this->post('/api/members', ['email' => 'south@example.test', 'role' => 'member', 'unitIds' => [$south]]);
        self::assertResponseStatusCodeSame(403);
        $this->post('/api/members', ['email' => 'everywhere@example.test', 'role' => 'member', 'unitIds' => null]);
        self::assertResponseStatusCodeSame(403);
        $this->post('/api/members', ['email' => 'north2@example.test', 'role' => 'member', 'unitIds' => [$north]]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testASuspendedMemberLosesAccessOnTheNextRequestAndRegainsIt(): void
    {
        $this->givenATenant();
        $this->makeUser('pat@example.test', Membership::ROLE_MEMBER);
        $this->givenIAmSignedIn();
        $pat = $this->members()['pat@example.test'];

        $this->signInAs('pat@example.test');
        $this->post('/api/members/' . $pat['id'] . '/suspend');
        self::assertResponseStatusCodeSame(403, 'pat cannot manage anyone, themselves included');

        $patsSession = $this->client->getServerParameter('HTTP_AUTHORIZATION');
        $this->get('/api/projects');
        self::assertResponseIsSuccessful();

        $this->signInAs('owner@example.test');
        $this->post('/api/members/' . $pat['id'] . '/suspend');
        self::assertResponseIsSuccessful();

        // The same token, still unexpired: the session it names no longer
        // exists, so it is refused outright rather than narrowed.
        $this->client->setServerParameter('HTTP_AUTHORIZATION', $patsSession);
        $this->get('/api/projects');
        self::assertResponseStatusCodeSame(401);
        $this->signInAs('owner@example.test');

        $this->post('/api/members/' . $pat['id'] . '/reactivate');
        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->json()['status']);
        $this->post('/api/members/' . $pat['id'] . '/reactivate');
        self::assertResponseStatusCodeSame(409, 'only a suspension is lifted');
    }

    public function testAPlainMemberSeesPeopleButInvitesAndChangesNobody(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn(Membership::ROLE_MEMBER, 'member@example.test');
        $me = $this->members()['member@example.test'];
        self::assertFalse($me['manageable']);

        $this->post('/api/members', ['email' => 'x@example.test', 'role' => 'member']);
        self::assertResponseStatusCodeSame(403);
        $this->post('/api/members/' . $me['id'] . '/access', ['role' => 'admin', 'unitIds' => null]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownInvitationLinkIsRefusedLikeAnyOther(): void
    {
        $this->givenATenant();

        $this->get('/api/auth/invitation?token=nonsense');
        self::assertResponseStatusCodeSame(404);
        $this->post('/api/auth/invitation', ['token' => 'nonsense', 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(400);
    }
}
