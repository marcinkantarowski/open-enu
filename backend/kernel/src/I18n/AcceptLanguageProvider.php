<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\I18n;

use Symfony\Component\HttpFoundation\Request;

/**
 * The browser's stated preference, narrowed to the locales we ship.
 *
 * Lowest-priority real signal: anything the user or their tenant explicitly
 * chose should win over what their browser happens to send.
 */
final readonly class AcceptLanguageProvider implements LocalePreferenceProviderInterface
{
    /** @param list<string> $enabled */
    public function __construct(private array $enabled)
    {
    }

    public function priority(): int
    {
        return 0;
    }

    public function preferredLocale(Request $request): ?string
    {
        // getPreferredLanguage already does the quality-value ordering and the
        // intersection; passing the enabled list is what stops it returning a
        // locale we have no catalogue for.
        return $request->getPreferredLanguage($this->enabled);
    }
}
