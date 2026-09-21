<?php

declare(strict_types=1);

namespace App\Module\Attachment\Command;

use OpenEnu\Kernel\Command\CommandInterface;

/**
 * Storing a file is a state change, so it goes through the bus like any other -
 * which is how "who uploaded what, and when" ends up in the audit trail without
 * anyone remembering to log it.
 */
final readonly class UploadAttachment implements CommandInterface
{
    public function __construct(
        public string $filename,
        public string $contents,
        public string $contentType,
        public int $size,
        public ?string $ownerType = null,
        public ?string $ownerId = null,
    ) {
    }

    public function auditAction(): string
    {
        return 'attachment.uploaded';
    }

    public function auditSubjectId(): ?string
    {
        return $this->ownerId;
    }
}
