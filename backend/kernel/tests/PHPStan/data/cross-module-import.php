<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Sales\Contract\OrderReaderInterface;
use App\Module\Sales\Entity\Order;
use App\Module\Sales\Event\OrderPlaced;
use App\Module\Sales\Service\OrderService;
use App\Module\Billing\Entity\Invoice;
use OpenEnu\Kernel\Dto\ListResponse;

final class InvoicingService
{
    public function __construct(
        private OrderReaderInterface $orders,
        private OrderService $forbidden,
    ) {
    }
}
