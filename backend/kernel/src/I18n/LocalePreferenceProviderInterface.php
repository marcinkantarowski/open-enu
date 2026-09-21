<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\I18n;

use Symfony\Component\HttpFoundation\Request;

/**
 * Contributes a locale preference, in priority order.
 *
 * An extension surface (.ai/platform/PLAN.md §6.10). The kernel knows only about
 * `Accept-Language`; the user's stored preference and the tenant's default are
 * things the Identity and Tenant modules know, and they add them by
 * implementing this rather than by editing the resolver.
 */
interface LocalePreferenceProviderInterface
{
    /** Higher runs first. Kernel's header-based provider sits at 0. */
    public function priority(): int;

    /** The preferred locale, or null to defer to the next provider. */
    public function preferredLocale(Request $request): ?string;
}
