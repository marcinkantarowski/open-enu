<?php

declare(strict_types=1);

namespace App\Module\Settings\Controller\Api;

use App\Module\Settings\Command\SetTenantFlag;
use App\Module\Settings\Repository\SettingOverrideRepository;
use App\Module\Settings\Repository\SettingRepository;
use OpenEnu\Kernel\Command\CommandBusInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What a tenant may see and change about its own settings.
 *
 * Only settings marked `tenantEditable` can be changed here. The rest are
 * platform kill switches: a tenant being able to re-enable a feature an operator
 * turned off during an incident would defeat the point of turning it off.
 */
final readonly class TenantSettingsController
{
    public function __construct(
        private SettingRepository $settings,
        private SettingOverrideRepository $overrides,
        private CommandBusInterface $commands,
        private ScopeContext $scope,
    ) {
    }

    #[Route('/api/settings', name: 'settings_index', methods: ['GET'])]
    #[IsGranted('settings.view')]
    public function index(): JsonResponse
    {
        $effective = [];
        foreach ($this->overrides->forCurrentTenant() as $override) {
            $effective[$override->setting()->identifier()] = $override->value();
        }

        return new JsonResponse([
            'items' => array_map(
                static fn (object $s): array => [
                    ...$s->toArray(),
                    // What actually applies here, which is the question being
                    // asked - the default alone is rarely the answer.
                    'effectiveValue' => $effective[$s->identifier()] ?? $s->defaultValue(),
                    'overridden' => \array_key_exists($s->identifier(), $effective),
                ],
                $this->settings->all(),
            ),
        ]);
    }

    #[Route('/api/settings/{identifier}', name: 'settings_set', methods: ['PUT'])]
    #[IsGranted('settings.manage')]
    public function set(string $identifier, Request $request): JsonResponse
    {
        $setting = $this->settings->byIdentifier($identifier)
            ?? throw new NotFoundHttpException(sprintf('No setting "%s".', $identifier));

        if (!$setting->isTenantEditable()) {
            throw new BadRequestHttpException('This setting is managed by the platform.');
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent() ?: '{}', true) ?: [];

        if (!\array_key_exists('value', $body)) {
            // Required explicitly rather than defaulted: `null` is a legitimate
            // value, so "absent" and "set to null" must not be the same request.
            throw new BadRequestHttpException('"value" is required.');
        }

        $tenantId = $this->scope->tenantId() ?? throw new BadRequestHttpException('No tenant in scope.');

        return new JsonResponse($this->commands->dispatch(
            new SetTenantFlag($tenantId, $identifier, $body['value']),
        ));
    }
}
