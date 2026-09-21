<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use OpenEnu\Kernel\Notification\NotificationDefinition;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The email half of a notification.
 *
 * Queued by Messenger like every other mail here, so a slow SMTP server cannot
 * fail the request that raised the notification.
 *
 * Both the subject and the body go through the translator with the recipient's
 * locale: the feed stores translation arguments precisely so the same
 * notification can be read in a different language than it was written in.
 */
final readonly class NotificationMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private string $fromAddress,
        private string $appUrl,
    ) {
    }

    /** @param array<string, scalar|null> $context */
    public function send(string $to, NotificationDefinition $definition, array $context, string $locale = 'en'): void
    {
        $arguments = [];
        foreach ($context as $key => $value) {
            $arguments['%' . $key . '%'] = (string) $value;
        }

        $this->mailer->send(
            (new TemplatedEmail())
                ->from(new Address($this->fromAddress))
                ->to($to)
                ->subject($this->translator->trans($definition->titleKey, $arguments, null, $locale))
                ->htmlTemplate('@notification/email/notification.html.twig')
                ->context([
                    'bodyKey' => $definition->bodyKey,
                    'arguments' => $arguments,
                    'locale' => $locale,
                    'url' => $this->appUrl . '/notifications',
                ]),
        );
    }
}
