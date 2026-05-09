<?php

namespace App\Service\Auth;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends transactional auth emails: verify-your-email + password-reset.
 * Templates live under templates/emails/auth/.
 *
 * Until MAILER_DSN is set to something real (Resend, Postmark, SMTP)
 * these go to the null transport and the link is logged to monolog so
 * developers can copy it from the console.
 */
final class AuthMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFromAddress,
        private readonly string $mailerFromName,
    ) {}

    public function sendVerificationEmail(User $user, string $plainToken): void
    {
        $url = $this->urls->generate('auth_verify_email', [
            '_locale' => $user->getDefaultLocale(),
            'token'   => $plainToken,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->logger->info('Verification email queued', ['email' => $this->mask($user->getEmail()), 'url_preview' => $this->maskUrl($url)]);

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($user->getEmail())
            ->subject('Confirm your Settlepay email')
            ->htmlTemplate('emails/auth/verify_email.html.twig')
            ->context(['url' => $url, 'user' => $user]);

        $this->mailer->send($email);
    }

    public function sendPasswordResetEmail(User $user, string $plainToken): void
    {
        $url = $this->urls->generate('auth_reset_password', [
            '_locale' => $user->getDefaultLocale(),
            'token'   => $plainToken,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->logger->info('Password-reset email queued', ['email' => $this->mask($user->getEmail()), 'url_preview' => $this->maskUrl($url)]);

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($user->getEmail())
            ->subject('Reset your Settlepay password')
            ->htmlTemplate('emails/auth/reset_password.html.twig')
            ->context(['url' => $url, 'user' => $user]);

        $this->mailer->send($email);
    }

    /** Mask emails in logs per CLAUDE.md security checklist. */
    private function mask(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 2) return '***';
        return substr($email, 0, 2) . str_repeat('*', max(0, $at - 2)) . substr($email, $at);
    }

    /** Hide the token in logs — the URL is the credential. */
    private function maskUrl(string $url): string
    {
        return preg_replace('#/(verify-email|reset-password)/[^/?]+#', '/$1/***', $url);
    }
}
