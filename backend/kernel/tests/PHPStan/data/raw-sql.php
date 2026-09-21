<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use Doctrine\DBAL\Connection;
use OpenEnu\Kernel\Attribute\Unscoped;

final class ReportingService
{
    public function __construct(private Connection $db)
    {
    }

    // Not a Repository: refused outright, wherever the attribute is.
    public function totals(): mixed
    {
        return $this->db->fetchAllAssociative('SELECT * FROM invoices');
    }

    #[Unscoped(reason: 'still not a repository')]
    public function totalsWithAttribute(): mixed
    {
        return $this->db->fetchOne('SELECT count(*) FROM invoices');
    }
}
