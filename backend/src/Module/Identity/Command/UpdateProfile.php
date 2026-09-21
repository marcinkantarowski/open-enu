<?php

declare(strict_types=1);

namespace App\Module\Identity\Command;

use OpenEnu\Kernel\Command\CommandInterface;

/**
 * Changing your own display name and language.
 *
 * Deliberately does NOT carry the email address. Changing an address is an
 * account-recovery vector - it has to re-verify the new one and notify the old,
 * which is a different command with a different security story, not an extra
 * field here.
 */
final readonly class UpdateProfile implements CommandInterface
{
    public function __construct(
        public string $userId,
        public ?string $displayName,
        public ?string $locale,
    ) {
    }

    public function auditAction(): string
    {
        return 'identity.profile.updated';
    }

    public function auditSubjectId(): string
    {
        return $this->userId;
    }
}
