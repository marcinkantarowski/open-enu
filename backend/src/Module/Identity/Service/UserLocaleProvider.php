<?php

declare(strict_types=1);

namespace App\Module\Identity\Service;

use App\Module\Identity\Entity\User;
use OpenEnu\Kernel\I18n\LocalePreferenceProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

/**
 * The user's stored preference, which outranks their browser's.
 *
 * Priority 100, well above the kernel's Accept-Language provider at 0: something
 * the person explicitly chose should beat whatever their browser happens to
 * send. Registered by implementing the interface - nothing central is edited
 * (.ai/platform/PLAN.md §6.10).
 */
final readonly class UserLocaleProvider implements LocalePreferenceProviderInterface
{
    public function __construct(private Security $security)
    {
    }

    public function priority(): int
    {
        return 100;
    }

    public function preferredLocale(Request $request): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->locale() : null;
    }
}
