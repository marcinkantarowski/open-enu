<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;

final class InvoiceController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function show(EntityManagerInterface $alsoBad): void
    {
    }
}
