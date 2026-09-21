<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use League\Flysystem\FilesystemOperator;
use Psr\Cache\CacheItemPoolInterface;
use OpenEnu\Kernel\Cache\TenantCache;
use OpenEnu\Kernel\Storage\StorageInterface;

final class ReportService
{
    public function __construct(
        private CacheItemPoolInterface $rawCache,
        private FilesystemOperator $rawFiles,
        private TenantCache $cache,
        private StorageInterface $storage,
    ) {
    }

    public function write(): void
    {
        file_put_contents('/tmp/report.csv', 'data');
    }
}
