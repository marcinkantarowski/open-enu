<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Gdpr;

/**
 * A module holding data about a person.
 *
 * Export and erasure must reach every module or the answer is wrong, and a
 * module that forgets to implement this is invisible - so an arch test fails the
 * build for any entity with a `user_id` whose module does not.
 *
 * That check is why "audit plus encryption equals GDPR" stops being a claim and
 * starts being something the build enforces.
 */
interface GdprSubjectInterface
{
    /**
     * Everything held about this person, as plain data.
     *
     * @return iterable<string, array<int, array<string, mixed>>> label => rows
     */
    public function exportFor(string $userId): iterable;

    /**
     * Remove or anonymise it.
     *
     * Which of the two is the module's call: an audit trail is anonymised
     * because deleting it would destroy a record required for other reasons,
     * while a draft document is simply deleted. What matters is that the person
     * is no longer identifiable afterwards.
     *
     * @return int rows affected, for the operator's report
     */
    public function eraseFor(string $userId): int;
}
