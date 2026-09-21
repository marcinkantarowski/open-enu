<?php

declare(strict_types=1);

namespace App\Module\Settings\Controller\Api;

use App\Module\Settings\Command\SetTenantFlag;
use App\Module\Settings\Repository\SettingRepository;
use OpenEnu\Kernel\Command\CommandBusInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The operator's view: every setting, and the ability to override it for any
 * tenant.
 *
 * This is the kill switch in its operational form - turning a feature off for
 * one customer at 3am without a deploy, which on a single host with no
 * blue/green is the difference between an incident and an outage (.ai/platform/PLAN.md §8.3).
 */
final readonly class PlatformSettingsController
{
    public function __construct(
        private SettingRepository $settings,
        private CommandBusInterface $commands,
        private ScopeContext $scope,
    ) {
    }

    #[Route('/api/manager/settings', name: 'manager_settings_index', methods: ['GET'])]
    #[IsGranted('ROLE_PLATFORM_MANAGER')]
    public function index(): JsonResponse
    {
        return $this->scope->runUnscoped(
            'the operator console lists platform-wide settings',
            fn (): JsonResponse => new JsonResponse([
                'items' => array_map(static fn (object $s): array => $s->toArray(), $this->settings->all()),
            ]),
        );
    }

    #[Route(
        '/api/manager/tenants/{tenantId}/settings/{identifier}',
        name: 'manager_settings_set',
        methods: ['PUT'],
    )]
    #[IsGranted('ROLE_PLATFORM_MANAGER')]
    public function setForTenant(string $tenantId, string $identifier, Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent() ?: '{}', true) ?: [];

        if (!\array_key_exists('value', $body)) {
            throw new BadRequestHttpException('"value" is required.');
        }

        // No `tenantEditable` check: an operator may override anything, which is
        // precisely what distinguishes this endpoint from the tenant-facing one.
        return new JsonResponse($this->commands->dispatch(
            new SetTenantFlag($tenantId, $identifier, $body['value']),
        ));
    }
}
