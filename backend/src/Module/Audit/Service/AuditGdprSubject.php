<?php

declare(strict_types=1);

namespace App\Module\Audit\Service;

use App\Module\Audit\Repository\AuditEntryRepository;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Gdpr\GdprSubjectInterface;

/**
 * GDPR for the audit trail: anonymise, never delete.
 *
 * This is the case the interface's docblock exists for. Deleting audit rows on
 * request would destroy the record of everything that person did - including
 * actions taken ON them, and including the record of the erasure itself. Those
 * records are kept for reasons other than the subject's convenience (security
 * investigation, dispute, regulatory obligation), and those reasons survive the
 * request.
 *
 * So the identifier is replaced with a tombstone. What happened is still known;
 * who did it is not.
 */
final readonly class AuditGdprSubject implements GdprSubjectInterface
{
    private const string TOMBSTONE = 'erased';

    public function __construct(private AuditEntryRepository $entries)
    {
    }

    public function exportFor(string $userId): iterable
    {
        yield 'actions' => $this->entries->actionsBy($userId);
    }

    #[InfrastructureWrite(reason: 'GdprWalker audits the erasure as a whole; a command here would recurse into the audit trail it is editing')]
    public function eraseFor(string $userId): int
    {
        return $this->entries->anonymise($userId, self::TOMBSTONE);
    }
}
