<?php

declare(strict_types=1);

namespace App\Module\Identity\Service;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The three emails that authenticate someone.
 *
 * Queued rather than sent inline: an SMTP timeout must not fail a registration
 * that has already been committed. Messenger's `async` routing does that without
 * this class knowing.
 *
 * Every subject goes through the translator - a hard-coded one fails
 * `make i18n-check`, which is the point (ADR-0020).
 */
final readonly class IdentityMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private string $fromAddress,
        private string $appUrl,
    ) {
    }

    public function sendVerification(string $to, string $rawToken, string $locale): void
    {
        $this->send(
            $to,
            $this->translator->trans('identity.email.verify.subject', [], null, $locale),
            'verify',
            ['url' => $this->appUrl . '/verify?token=' . $rawToken, 'expiresInHours' => 24],
            $locale,
        );
    }

    public function sendPasswordReset(string $to, string $rawToken, string $locale): void
    {
        $this->send(
            $to,
            $this->translator->trans('identity.email.reset.subject', [], null, $locale),
            'reset',
            ['url' => $this->appUrl . '/reset-password?token=' . $rawToken, 'expiresInHours' => 1],
            $locale,
        );
    }

    public function sendInvitation(string $to, string $rawToken, string $tenantName, string $locale): void
    {
        $this->send(
            $to,
            $this->translator->trans('identity.email.invite.subject', ['%tenant%' => $tenantName], null, $locale),
            'invitation',
            ['url' => $this->appUrl . '/accept-invitation?token=' . $rawToken, 'tenantName' => $tenantName],
            $locale,
        );
    }

    /** @param array<string, mixed> $context */
    private function send(string $to, string $subject, string $template, array $context, string $locale): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromAddress))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate("@identity/email/{$template}.html.twig")
            ->context([...$context, 'locale' => $locale]);

        $this->mailer->send($email);
    }
}
