<?php

declare(strict_types=1);

namespace App\Module\Identity\Handler;

use App\Module\Identity\Command\UpdateProfile;
use App\Module\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpdateProfileHandler
{
    /** The locales the apps actually ship catalogues for (.ai/platform/PLAN.md §7.1). */
    private const array LOCALES = ['en', 'pl'];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(UpdateProfile $command): array
    {
        $user = $this->em->find(User::class, $command->userId)
            ?? throw new NotFoundHttpException('No such user.');

        if ($command->displayName !== null) {
            $user->setDisplayName(trim($command->displayName) ?: null);
        }

        if ($command->locale !== null) {
            if (!\in_array($command->locale, self::LOCALES, true)) {
                // Rejected rather than silently ignored: a locale that "saves"
                // and then does not apply is a bug report nobody can reproduce.
                throw new BadRequestHttpException(sprintf(
                    'Unsupported locale "%s". Supported: %s.',
                    $command->locale,
                    implode(', ', self::LOCALES),
                ));
            }

            $user->setLocale($command->locale);
        }

        $this->em->flush();

        return $user->toArray();
    }
}
