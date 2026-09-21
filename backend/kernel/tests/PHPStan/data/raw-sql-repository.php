<?php

declare(strict_types=1);

namespace App\Module\Billing\Repository;

use Doctrine\DBAL\Connection;
use OpenEnu\Kernel\Attribute\Unscoped;

final class InvoiceRepository
{
    public function __construct(private Connection $db)
    {
    }

    #[Unscoped(reason: 'platform-wide revenue report, deliberately cross-tenant')]
    public function platformTotals(): mixed
    {
        return $this->db->fetchAllAssociative('SELECT sum(total) FROM invoices');
    }

    // A Repository, but no attribute: the query would silently cross tenants
    // and nothing would make that findable later.
    public function undeclared(): mixed
    {
        return $this->db->fetchAllAssociative('SELECT * FROM invoices');
    }
}
