<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Quick smoke test for the configured MAILER_DSN.
 *
 *   bin/console app:email:test you@example.com
 *
 * Sends a plain "Settlepay test" email and reports success/failure.
 */
#[AsCommand(name: 'app:email:test', description: 'Send a test email to verify MAILER_DSN works.')]
class SendTestEmailCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $mailerFromAddress,
        private readonly string $mailerFromName,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('to', InputArgument::REQUIRED, 'Recipient email address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = (string) $input->getArgument('to');

        // Each test gets a unique subject + correlation id so repeated
        // sends don't look like a spam pattern to Gmail's bayes filter.
        // Real production emails (verification, paid receipts) are
        // already unique per-user; this only matters for these admin tests.
        $stamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $cid   = bin2hex(random_bytes(4));

        $text = <<<TXT
            Settlepay deliverability check ({$cid})

            This is an internal smoke test sent at {$stamp}.
            If you received this, MAILER_DSN, DKIM, and SPF are all working.

            Reply to this email if you have a question — we read replies.

            -- Settlepay
            https://settlepay.pro
            TXT;
        $text = preg_replace('/^ {12}/m', '', $text);

        $html = '<div style="font-family:Inter,Arial,sans-serif;color:#0f172a;line-height:1.6">'
            . '<p>Settlepay deliverability check <code style="color:#64748b">' . $cid . '</code></p>'
            . '<p>This is an internal smoke test sent at ' . $stamp . '. '
            . 'If you received this, <strong>MAILER_DSN, DKIM, and SPF are all working</strong>.</p>'
            . '<p style="color:#64748b">Reply to this email if you have a question — we read replies.</p>'
            . '<p style="color:#94a3b8;font-size:13px">— Settlepay · '
            . '<a href="https://settlepay.pro" style="color:#0d9488;text-decoration:none">settlepay.pro</a></p>'
            . '</div>';

        $email = (new Email())
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->replyTo(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($to)
            ->subject(sprintf('Settlepay deliverability check · %s', $cid))
            ->text($text)
            ->html($html);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $io->error(sprintf('%s: %s', get_class($e), $e->getMessage()));
            return Command::FAILURE;
        }

        $io->success("Email sent to {$to}");
        return Command::SUCCESS;
    }
}
