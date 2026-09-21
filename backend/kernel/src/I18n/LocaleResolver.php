<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\I18n;

use Symfony\Component\HttpFoundation\Request;

/**
 * Decides which language a response speaks.
 *
 * Order (first non-null wins), highest priority first:
 *   1. the user's stored preference       (Identity module, Phase 3)
 *   2. the tenant's default               (Tenant module, Phase 3)
 *   3. Accept-Language, intersected with what we actually ship
 *   4. the default locale
 *
 * Step 3's intersection is the important one: honouring a requested locale that
 * has no catalogue produces a page of untranslated keys, which is worse than
 * confidently serving the default.
 */
final readonly class LocaleResolver
{
    /**
     * @param iterable<LocalePreferenceProviderInterface> $providers
     * @param list<string>                                $enabled
     */
    public function __construct(
        private iterable $providers,
        private array $enabled,
        private string $default,
    ) {
    }

    public function resolve(Request $request): string
    {
        $providers = iterator_to_array($this->providers, false);
        usort(
            $providers,
            static fn (LocalePreferenceProviderInterface $a, LocalePreferenceProviderInterface $b): int
                => $b->priority() <=> $a->priority(),
        );

        foreach ($providers as $provider) {
            $locale = $provider->preferredLocale($request);
            if ($locale !== null && \in_array($locale, $this->enabled, true)) {
                return $locale;
            }
        }

        return $this->default;
    }

    /** @return list<string> */
    public function enabledLocales(): array
    {
        return $this->enabled;
    }
}
