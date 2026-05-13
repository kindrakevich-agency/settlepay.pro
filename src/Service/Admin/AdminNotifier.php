<?php

namespace App\Service\Admin;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Side-channel notifications to platform admins.
 *
 * Recipients are read from the `ADMIN_EMAILS` env var (comma-separated,
 * lowercased). When the var is empty, every notify call silently no-ops,
 * so we never have to guard call sites with `if (admins) { … }`.
 *
 * All sends are wrapped in try/catch — a mail failure must never block
 * the underlying user action (new signup, billing event, etc).
 */
final class AdminNotifier
{
    /** @var string[] */
    private readonly array $emails;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFromAddress,
        private readonly string $mailerFromName,
        string $adminEmails,
    ) {
        $this->emails = array_values(array_filter(array_map(
            fn(string $e) => strtolower(trim($e)),
            explode(',', $adminEmails),
        )));
    }

    public function notifyNewSignup(User $user, string $via): void
    {
        if (empty($this->emails)) {
            return;
        }
        try {
            $email = (new TemplatedEmail())
                ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
                ->subject(sprintf('[Settlepay admin] New signup: %s', $user->getEmail()))
                ->htmlTemplate('emails/admin/new_signup.html.twig')
                ->textTemplate('emails/admin/new_signup.txt.twig')
                ->context([
                    'user'        => $user,
                    'via'         => $via, // 'email' or 'google'
                    'signed_up_at'=> new \DateTimeImmutable(),
                ]);
            foreach ($this->emails as $to) {
                $email->addBcc($to);
            }
            // A To: header is required by some MTAs even when only BCC is used.
            // Send to the From address itself — invisible to BCC recipients.
            $email->to($this->mailerFromAddress);
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->warning('Admin signup notification failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
            ]);
        }
    }
}
