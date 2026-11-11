<?php

declare(strict_types=1);

namespace App\Module\Unit\Tests\Functional;

use App\Module\Example\Entity\Project;
use App\Module\Identity\Entity\Membership;
use App\Module\Unit\Entity\Unit;
use App\Tests\Support\ApiTestCase;

final class UnitApiTest extends ApiTestCase
{
    protected function fixtures(): array
    {
        return [Project::class, Unit::class];
    }

    /** @return array<string, mixed> */
    private function create(string $name, ?string $parentId = null, string $kind = 'site'): array
    {
        $this->post('/api/units', ['name' => $name, 'kind' => $kind, 'parentId' => $parentId]);
        self::assertResponseStatusCodeSame(201);

        return $this->json();
    }

    /** @return array<string, array<string, mixed>> name => unit, read back over the API */
    private function tree(): array
    {
        $this->get('/api/units');
        self::assertResponseIsSuccessful();

        return array_column($this->json()['items'], null, 'name');
    }

    public function testAnAdministratorBuildsRenamesMovesAndArchivesTheTree(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn(Membership::ROLE_ADMIN, 'admin@example.test');

        $north = $this->create('North', kind: 'region');
        $site = $this->create('Harbour', $north['id']);
        $south = $this->create('South', kind: 'region');

        self::assertSame(1, $site['depth']);
        self::assertSame($north['id'], $site['parentId']);

        $this->patch('/api/units/' . $site['id'], ['name' => 'Harbour Street', 'kind' => 'site'], '"' . $site['version'] . '"');
        self::assertSame('Harbour Street', $this->json()['name']);

        // A second edit made from the same stale read is a conflict, not an overwrite.
        $this->patch('/api/units/' . $site['id'], ['name' => 'Harbour Lane', 'kind' => 'site'], '"' . $site['version'] . '"');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Harbour Street', $this->json()['error']['current']['name'] ?? $this->tree()['Harbour Street']['name']);

        $this->post('/api/units/' . $site['id'] . '/move', ['parentId' => $south['id']]);
        self::assertResponseIsSuccessful();
        self::assertSame($south['id'], $this->tree()['Harbour Street']['parentId']);

        $this->post('/api/units/' . $south['id'] . '/archive');
        $tree = $this->tree();
        self::assertTrue($tree['South']['archived']);
        self::assertTrue($tree['Harbour Street']['archived'], 'Archiving a unit archives what is below it.');
        self::assertFalse($tree['North']['archived']);
    }

    public function testTheTreeRefusesACycleAndAnArchivedParent(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $north = $this->create('North');
        $site = $this->create('Harbour', $north['id']);

        $this->post('/api/units/' . $north['id'] . '/move', ['parentId' => $site['id']]);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('cannot move under itself', (string) $this->client->getResponse()->getContent());

        $this->post('/api/units/' . $north['id'] . '/archive');
        $this->post('/api/units', ['name' => 'Late', 'kind' => 'site', 'parentId' => $north['id']]);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('archived unit takes no new units', (string) $this->client->getResponse()->getContent());

        $this->post('/api/units', ['name' => '', 'kind' => 'site']);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('"name" must be a non-empty string', (string) $this->json()['error']['message']);
    }

    public function testAMemberReadsTheTreeButCannotChangeIt(): void
    {
        $this->givenATenant();
        $unit = new Unit($this->tenantId, 'North', 'region');
        $this->em->persist($unit);
        $this->em->flush();
        $this->givenIAmSignedIn(Membership::ROLE_MEMBER, 'member@example.test');

        self::assertArrayHasKey('North', $this->tree());

        foreach ([['POST', '/api/units'], ['PATCH', '/api/units/' . $unit->id()], ['POST', '/api/units/' . $unit->id() . '/move'], ['POST', '/api/units/' . $unit->id() . '/archive']] as [$method, $path]) {
            $this->request($method, $path, ['name' => 'Taken', 'kind' => 'x']);
            self::assertResponseStatusCodeSame(403, $method . ' ' . $path);
        }

        self::assertSame('North', $this->tree()['North']['name']);
    }

    public function testAMemberNarrowedToAUnitSeesOnlyItsRowsOverHttp(): void
    {
        $this->givenATenant();
        $north = new Unit($this->tenantId, 'North', 'region');
        $harbour = new Unit($this->tenantId, 'Harbour', 'site', $north);
        $south = new Unit($this->tenantId, 'South', 'region');
        foreach ([$north, $harbour, $south] as $unit) {
            $this->em->persist($unit);
        }
        foreach (['north' => $north, 'harbour' => $harbour, 'south' => $south, 'tenant' => null] as $name => $unit) {
            $project = new Project('ledger ' . $name, $this->tenantId);
            $project->placeIn($unit?->id());
            $this->em->persist($project);
        }
        $this->em->flush();

        $user = $this->givenIAmSignedIn(Membership::ROLE_ADMIN, 'north@example.test');
        $user->membershipIn($this->tenantId)?->narrowTo([$north->id()]);
        $this->em->flush();

        $this->get('/api/projects');
        $names = array_column($this->json()['items'], 'name');
        sort($names);
        self::assertSame(['ledger harbour', 'ledger north'], $names);

        $this->post('/api/projects', ['name' => 'smuggled', 'unitId' => $south->id()]);
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('outside the units', (string) $this->client->getResponse()->getContent());

        // Narrowed further while signed in: applies to the very next request.
        $this->scope->enterTenant($this->tenantId);
        $this->em->clear();
        $membership = $this->em->getRepository(Membership::class)->findOneBy(['tenantId' => $this->tenantId]);
        \assert($membership instanceof Membership);
        $membership->narrowTo([$harbour->id()]);
        $this->em->flush();

        $this->get('/api/projects');
        self::assertSame(['ledger harbour'], array_column($this->json()['items'], 'name'));
    }
}
