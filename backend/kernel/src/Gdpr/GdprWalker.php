<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Gdpr;

use OpenEnu\Kernel\Contract\AuditEntry;
use OpenEnu\Kernel\Contract\AuditLoggerInterface;

/**
 * Asks every module what it holds about a person, and tells every module to
 * forget them.
 *
 * Erasure runs in REVERSE dependency order - the mirror of setup. A module that
 * depends on another may hold references to its rows, so it must let go first;
 * erasing the dependency first can leave the dependent holding identifiers that
 * no longer resolve.
 *
 * Both operations are audited, because "we deleted your data" is a claim that
 * has to be evidenced later.
 */
final readonly class GdprWalker
{
    /** @param iterable<GdprSubjectInterface> $subjects in dependency order */
    public function __construct(
        private iterable $subjects,
        private AuditLoggerInterface $audit,
    ) {
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function export(string $userId): array
    {
        $bundle = [];

        foreach ($this->subjects as $subject) {
            foreach ($subject->exportFor($userId) as $label => $rows) {
                // Two modules may legitimately use the same label, so the key is
                // namespaced by the contributing class. That is still not unique
                // on its own - two instances of one class, or two anonymous
                // classes from the same expression, share a name - so a
                // collision gets a suffix rather than overwriting. Losing a
                // section of a subject-access response is not an acceptable
                // failure mode for a missing suffix.
                $key = $subject::class . '.' . $label;
                $unique = $key;
                for ($i = 2; isset($bundle[$unique]); $i++) {
                    $unique = $key . '#' . $i;
                }
                $bundle[$unique] = $rows;
            }
        }

        $this->audit->record(new AuditEntry(
            action: 'gdpr.subject.exported',
            subjectId: $userId,
            context: ['sections' => \count($bundle)],
            at: new \DateTimeImmutable(),
        ));

        return $bundle;
    }

    /** @return array<string, int> module => rows affected */
    public function erase(string $userId): array
    {
        $affected = [];

        foreach (array_reverse(iterator_to_array($this->subjects, false)) as $subject) {
            $affected[$subject::class] = $subject->eraseFor($userId);
        }

        $this->audit->record(new AuditEntry(
            action: 'gdpr.subject.erased',
            subjectId: $userId,
            context: ['rows' => array_sum($affected)],
            at: new \DateTimeImmutable(),
        ));

        return $affected;
    }
}
