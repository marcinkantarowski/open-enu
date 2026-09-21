<?php

declare(strict_types=1);

namespace App\Module\Manager\Controller\Api;

use App\Module\Manager\Service\OperatorLogin;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Operator login.
 *
 * The strictest rate limit in the system - 5 attempts per 15 minutes. One
 * operator account reaches every tenant's data, so it gets the tightest budget
 * rather than being treated like any other login.
 *
 * There is deliberately no registration, no password reset and no refresh.
 * Operator accounts are created by another operator (`app:manager:create` for
 * the first), and a lapsed session means logging in again - a reasonable thing
 * to ask of someone holding this much authority.
 */
final readonly class ManagerAuthController
{
    public function __construct(
        private OperatorLogin $logins,
        #[Target('managerLoginLimiter')] private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/api/manager/login', name: 'manager_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $limit = $this->limiter->create($request->getClientIp() ?? 'unknown')->consume();

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                'Too many attempts.',
            );
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent() ?: '{}', true) ?: [];

        $email = $body['email'] ?? null;
        $password = $body['password'] ?? null;

        if (!\is_string($email) || !\is_string($password)) {
            throw new BadRequestHttpException('"email" and "password" are required.');
        }

        return new JsonResponse($this->logins->authenticate($email, $password));
    }
}
