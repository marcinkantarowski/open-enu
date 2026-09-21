<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Crypto;

/**
 * Supplies the data-encryption key for a tenant.
 *
 * An interface from day one so that moving to a KMS later is a class rather
 * than a refactor of every encrypted column (ADR-0018). The default derives keys
 * from a master secret, which is what lets the stack boot with no external
 * service.
 */
interface KeyProviderInterface
{
    /** @return non-empty-string 32 raw bytes */
    public function keyFor(?string $tenantId): string;
}
