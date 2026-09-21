<?php

declare(strict_types=1);

namespace App\Module\Progress\Controller\Api;

use App\Module\Progress\Repository\ProgressJobRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class ProgressController
{
    public function __construct(private ProgressJobRepository $jobs)
    {
    }

    #[Route('/api/progress/{id}', name: 'progress_show', methods: ['GET'])]
    #[IsGranted('progress.view')]
    public function show(string $id): JsonResponse
    {
        // Another tenant's job is NOT FOUND rather than forbidden: the scope
        // filter removes it from the query, so this cannot leak its existence.
        $job = $this->jobs->find($id) ?? throw new NotFoundHttpException('No such job.');

        return new JsonResponse($job->toArray());
    }
}
