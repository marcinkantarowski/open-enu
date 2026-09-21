<?php

declare(strict_types=1);

namespace App\Module\Billing\Tests\Functional;

use App\Module\Sales\Entity\Order;
use App\Module\Sales\Service\OrderService;

final class InvoicingTest
{
    public function __construct(
        private Order $order,
        private OrderService $orders,
    ) {
    }
}
