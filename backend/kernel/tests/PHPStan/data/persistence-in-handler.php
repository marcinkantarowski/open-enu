<?php

declare(strict_types=1);

namespace App\Module\Billing\Handler;

use Doctrine\ORM\EntityManagerInterface;

final class VoidInvoiceHandler
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function __invoke(object $command): void
    {
        $this->em->flush();
    }
}
