<?php

declare(strict_types=1);

namespace App\Module\Example\Command;

use OpenEnu\Kernel\Command\CommandInterface;

/**
 * Asks for every active project to be archived.
 *
 * A command, so the *request* is audited against the person who made it - even
 * though the work itself happens in a worker afterwards.
 */
final readonly class ArchiveProjects implements CommandInterface
{
    public function auditAction(): string
    {
        return 'example.projects.archive_requested';
    }

    public function auditSubjectId(): ?string
    {
        // A bulk action has no single subject; the audit entry's `after` holds
        // the job id, which is what ties it to the work that followed.
        return null;
    }
}
