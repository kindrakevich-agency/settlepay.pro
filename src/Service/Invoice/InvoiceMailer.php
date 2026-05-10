<?php

namespace App\Service\Invoice;

use App\Entity\Invoice;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends transactional emails about invoices: "your invoice is ready",
 * "payment received", "overdue reminder".
 */
final class InvoiceMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFromAddress,
        private readonly string $mailerFromName,
    ) {}

    /** Sends "your invoice is ready" to client_email. No-op if absent. */
    public function sendInvoiceToClient(Invoice $invoice): bool
    {
        $clientEmail = $invoice->getClientEmail();
        if (!$clientEmail) {
            return false;
        }

        $payUrl = $this->urls->generate('public_payment_checkout', [
            '_locale' => $invoice->getUser()->getDefaultLocale(),
            'uuid'    => $invoice->getUuid(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $subject = sprintf('Invoice %s from %s', $invoice->getNumber(), $invoice->getUser()->getBusinessName() ?: 'Settlepay');

        $amountDecimal = number_format($invoice->getAmountCents() / 100, 2, '.', ',');
        $dueDateText   = $invoice->getDueDate()
            ? $invoice->getDueDate()->format('F j, Y')   // "May 24, 2026" — recipient locale is unknown, English is the safe default
            : null;

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFromAddress, $invoice->getUser()->getBusinessName() ?: $this->mailerFromName))
            ->to($clientEmail)
            ->replyTo(new Address($invoice->getUser()->getEmail(), $invoice->getUser()->getBusinessName() ?: $invoice->getUser()->getDisplayName() ?: ''))
            ->subject($subject)
            ->htmlTemplate('emails/invoices/sent.html.twig')
            ->textTemplate('emails/invoices/sent.txt.twig')
            ->context([
                'invoice'        => $invoice,
                'pay_url'        => $payUrl,
                'amount_decimal' => $amountDecimal,
                'due_date_text'  => $dueDateText,
            ]);

        // List-Unsubscribe: Gmail / Outlook actively bump trust scores for
        // transactional emails that declare an unsubscribe path even when
        // unsubscribe doesn't really apply. Mailto routes to the freelancer
        // who can manually act on the request.
        $email->getHeaders()
            ->addTextHeader('List-Unsubscribe', sprintf('<mailto:%s?subject=Unsubscribe>', $invoice->getUser()->getEmail()))
            ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Invoice email failed', [
                'invoice_uuid' => $invoice->getUuid(),
                'to_masked'    => $this->maskEmail($clientEmail),
                'error'        => $e->getMessage(),
            ]);
            return false;
        }
        return true;
    }

    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 2) return '***';
        return substr($email, 0, 2) . str_repeat('*', max(0, $at - 2)) . substr($email, $at);
    }
}
