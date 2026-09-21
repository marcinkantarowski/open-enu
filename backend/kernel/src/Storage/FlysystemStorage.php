<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Storage;

use League\Flysystem\FilesystemOperator;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\Uid\Ulid;

/**
 * Flysystem-backed storage with tenant-scoped keys.
 *
 * Keys are `tenants/{tenant}/{module}/{ulid}.{ext}`:
 *   • the tenant segment makes cross-tenant access a path traversal rather than
 *     an off-by-one, and a whole tenant's files deletable as one prefix;
 *   • the ULID means an uploaded filename never becomes a path, so `../../` and
 *     collisions are both impossible;
 *   • the original filename lives on the owning record, where it belongs.
 */
final readonly class FlysystemStorage implements StorageInterface
{
    public function __construct(
        private FilesystemOperator $filesystem,
        private ScopeContext $scope,
        private UrlSignerInterface $signer,
    ) {
    }

    public function write(string $module, string $filename, mixed $contents, string $contentType): string
    {
        $key = $this->buildKey($module, $filename);

        if (\is_string($contents)) {
            $this->filesystem->write($key, $contents, ['ContentType' => $contentType]);
        } elseif (\is_resource($contents)) {
            $this->filesystem->writeStream($key, $contents, ['ContentType' => $contentType]);
        } else {
            throw new \InvalidArgumentException(sprintf(
                'Contents must be a string or a stream resource, %s given.',
                get_debug_type($contents),
            ));
        }

        return $key;
    }

    public function read(string $key): string
    {
        return $this->filesystem->read($key);
    }

    public function readStream(string $key): mixed
    {
        return $this->filesystem->readStream($key);
    }

    public function delete(string $key): void
    {
        $this->filesystem->delete($key);
    }

    public function exists(string $key): bool
    {
        return $this->filesystem->fileExists($key);
    }

    public function size(string $key): int
    {
        return $this->filesystem->fileSize($key);
    }

    public function temporaryUrl(string $key, int $ttlSeconds = 300): string
    {
        return $this->signer->sign($key, $ttlSeconds);
    }

    private function buildKey(string $module, string $filename): string
    {
        $extension = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? '.' . $extension : '';

        return sprintf(
            'tenants/%s/%s/%s%s',
            $this->scope->tenantId() ?? 'global',
            preg_replace('/[^a-z0-9_-]/i', '', $module) ?? 'unknown',
            (new Ulid())->toBase32(),
            $extension,
        );
    }
}
