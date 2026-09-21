<?php

declare(strict_types=1);

namespace App\Module\Manager\Controller\Api;

use App\Module\Manager\Repository\WorkerQueueRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Is the background work actually running?
 *
 * The first question an operator asks when something "didn't happen". Two
 * numbers answer most of it: how much is waiting, and how much has failed.
 */
final readonly class SchedulerStatusController
{
    public function __construct(private WorkerQueueRepository $queues)
    {
    }

    #[Route('/api/manager/workers', name: 'manager_worker_status', methods: ['GET'])]
    #[IsGranted('ROLE_PLATFORM_MANAGER')]
    public function status(): JsonResponse
    {
        return new JsonResponse([
            'queues' => $this->queues->queueDepths(),
            'failed' => $this->queues->failedCount(),
        ]);
    }
}
