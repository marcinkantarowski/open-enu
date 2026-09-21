<?php

declare(strict_types=1);

namespace App\Module\Progress\Tests\Functional;

use App\Module\Progress\Entity\ProgressJob;
use App\Tests\Support\ApiTestCase;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\Uid\Ulid;

/**
 * Polling a long job.
 *
 * The endpoint is trivial; what it must never do is answer about somebody
 * else's job, because the id is the only thing a caller needs to ask.
 */
final class ProgressApiTest extends ApiTestCase
{
    protected function fixtures(): array
    {
        return [ProgressJob::class];
    }

    public function testAJobReportsItsPercentage(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $id = $this->makeJob($this->tenantId, total: 8, done: 2);

        $this->get('/api/progress/' . $id);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('running', $body['status']);
        self::assertSame(2, $body['done']);
        // Computed server-side, so every client agrees on what the bar shows.
        self::assertSame(25, $body['percent']);
    }

    public function testAnotherTenantsJobIsNotFound(): void
    {
        $this->givenATenant('alpha');
        $this->givenIAmSignedIn();
        $stranger = $this->givenATenant('beta');
        $id = $this->makeJob($stranger, total: 4, done: 4);

        $this->scope->enter([ScopeContext::TENANT => $this->tenantId]);
        $this->get('/api/progress/' . $id);

        // Not found rather than forbidden: the scope filter removes the row, so
        // the answer cannot confirm that the id is real.
        self::assertResponseStatusCodeSame(404);
    }

    private function makeJob(string $tenantId, int $total, int $done): string
    {
        // Base32 ULID, as PersistentProgressReporter mints them: the column is
        // 32 characters, and a uuid string does not fit.
        $id = (new Ulid())->toBase32();
        $job = new ProgressJob($id, $tenantId, 'example.archive', $total, 'Archiving');
        $job->advance($done, null);

        $this->em->persist($job);
        $this->em->flush();

        return $id;
    }
}
