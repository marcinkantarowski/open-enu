<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Logging;

use OpenEnu\Kernel\Http\RequestId;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Puts the correlation id on every log line. The first of several providers -
 * tenant and user follow in Phase 3.
 */
final readonly class RequestIdContextProvider implements LogContextProviderInterface
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function contribute(): array
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return [];
        }

        $id = RequestId::fromRequest($request);

        return $id === null ? [] : [
            'request_id' => $id,
            'route' => (string) ($request->attributes->get('_route') ?? ''),
        ];
    }
}
