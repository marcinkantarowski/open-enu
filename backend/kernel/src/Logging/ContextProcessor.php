<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Merges every registered provider's fields into each log record.
 *
 * Wrapped in a try/catch on purpose: a provider that throws while the
 * application is already failing would replace a useful error with a confusing
 * one from the logger itself. A broken provider degrades the log; it must never
 * take down the request.
 */
final readonly class ContextProcessor implements ProcessorInterface
{
    /** @param iterable<LogContextProviderInterface> $providers */
    public function __construct(private iterable $providers)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        foreach ($this->providers as $provider) {
            try {
                foreach ($provider->contribute() as $key => $value) {
                    $extra[$key] = $value;
                }
            } catch (\Throwable) {
                // Deliberately swallowed - see the class docblock.
            }
        }

        return $record->with(extra: $extra);
    }
}
