<?php

namespace App\Command;

use App\Enum\InvoiceStatus;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Service\Invoice\InvoiceMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manually re-fires the "invoice paid" notifications (client receipt +
 * freelancer alert, both with PDF attached) for an already-paid invoice.
 *
 *   bin/console app:invoice:resend-paid-receipt INV-2026-0004
 *
 * Use case: support fallback when the original send failed (mailer
 * outage, listener running on stale code at the moment of match, etc).
 * Idempotent on the email side — Resend will accept duplicate sends.
 */
#[AsCommand(
    name: 'app:invoice:resend-paid-receipt',
    description: 'Resend paid-invoice notifications (client receipt + freelancer alert) for a paid invoice.',
)]
final class ResendPaidReceiptCommand extends Command
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly PaymentRepository $payments,
        private readonly InvoiceMailer $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('number', InputArgument::REQUIRED, 'Invoice number, e.g. INV-2026-0004');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $number = trim((string) $input->getArgument('number'));

        $invoice = $this->invoices->findOneBy(['number' => $number]);
        if (!$invoice) {
            $io->error("Invoice {$number} not found.");
            return Command::FAILURE;
        }
        if ($invoice->getStatus() !== InvoiceStatus::Paid) {
            $io->error("Invoice {$number} is not paid (status: {$invoice->getStatus()->value}).");
            return Command::FAILURE;
        }
        $payment = $this->payments->findOneBy(['invoice' => $invoice]);
        if (!$payment) {
            $io->error("No payment row found for invoice {$number}.");
            return Command::FAILURE;
        }

        $io->writeln(sprintf(
            'Resending paid notifications for <info>%s</info> · client=%s · freelancer=%s',
            $invoice->getNumber(),
            $invoice->getClientEmail() ?: '(none)',
            $invoice->getUser()->getEmail()
        ));

        $this->mailer->sendPaidNotifications($invoice, $payment);

        $io->success('Sent. Check Resend dashboard + recipient inboxes.');
        return Command::SUCCESS;
    }
}
