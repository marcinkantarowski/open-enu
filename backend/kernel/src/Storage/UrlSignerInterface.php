<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Storage;

/**
 * Turns a storage key into a time-limited URL.
 *
 * Separate from StorageInterface because the two differ per backend: S3 signs
 * natively, while a local filesystem needs the application to sign a route.
 */
interface UrlSignerInterface
{
    public function sign(string $key, int $ttlSeconds): string;
}
