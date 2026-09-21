<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use Doctrine\ORM\EntityManagerInterface;

final class InvoiceService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function void(object $invoice): void
    {
        $this->em->persist($invoice);
        $this->em->flush();
    }
}
