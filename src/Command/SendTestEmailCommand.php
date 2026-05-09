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
    public function __construct(private readonly MailerInterface $mailer) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('to', InputArgument::REQUIRED, 'Recipient email address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = (string) $input->getArgument('to');

        $email = (new Email())
            ->from(new Address('hello@settlepay.pro', 'Settlepay'))
            ->to($to)
            ->subject('Settlepay — mailer smoke test')
            ->text("If you're reading this, MAILER_DSN is configured correctly.\n\nSent from settlepay.pro.")
            ->html('<p>If you can read this, <strong>MAILER_DSN is configured correctly</strong>.</p><p style="color:#64748b;font-size:14px">Sent from <a href="https://settlepay.pro">settlepay.pro</a>.</p>');

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
