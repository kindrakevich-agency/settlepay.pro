<?php

namespace App\Service\Invoice;

use App\Entity\Invoice;
use App\Entity\Payment;
use App\Service\Blockchain\ChainRegistry;
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
        private readonly InvoicePdfRenderer $pdf,
        private readonly ChainRegistry $chains,
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

        // Attach the rendered PDF — same number on the cover as the email
        // subject, so the client can save the file straight into their
        // accounting folder. Locale matches the freelancer's default.
        try {
            $pdfBytes = $this->pdf->render($invoice, $invoice->getUser()->getDefaultLocale());
            $email->attach($pdfBytes, $this->pdf->filenameFor($invoice), 'application/pdf');
        } catch (\Throwable $e) {
            // PDF generation failing should NOT block the email — the link
            // in the body is the source of truth for payment. Log + carry on.
            $this->logger->warning('Invoice PDF attach skipped', [
                'invoice_uuid' => $invoice->getUuid(),
                'error'        => $e->getMessage(),
            ]);
        }

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

    /**
     * Sends "payment received — here's your receipt" to the client AND a
     * "you got paid" notification to the freelancer. Both carry the PDF
     * receipt as an attachment. Called from PaymentMatcher after the
     * status flip to Paid.
     */
    public function sendPaidNotifications(Invoice $invoice, Payment $payment): void
    {
        $paidAt        = $invoice->getPaidAt() ?? new \DateTimeImmutable();
        $amountDecimal = number_format($invoice->getAmountCents() / 100, 2, '.', ',');
        $paidAtText    = $paidAt->format('Y-m-d H:i');
        $txUrl         = $this->buildTxUrl($payment->getChainId(), $payment->getTxHash());

        // Render the PDF once, attach to both emails. PDF failure must NOT
        // block notifications — the receipt is also available in the
        // dashboard. Log + carry on.
        $pdfBytes = null;
        try {
            $pdfBytes = $this->pdf->render($invoice, $invoice->getUser()->getDefaultLocale());
        } catch (\Throwable $e) {
            $this->logger->warning('Paid-email PDF render failed', [
                'invoice_uuid' => $invoice->getUuid(),
                'error'        => $e->getMessage(),
            ]);
        }

        $ctx = [
            'invoice'        => $invoice,
            'payment'        => $payment,
            'amount_decimal' => $amountDecimal,
            'paid_at_text'   => $paidAtText,
            'tx_url'         => $txUrl,
        ];

        // 1) Client receipt — only if we have an email on file.
        if ($invoice->getClientEmail()) {
            $clientEmail = (new TemplatedEmail())
                ->from(new Address($this->mailerFromAddress, $invoice->getUser()->getBusinessName() ?: $this->mailerFromName))
                ->to($invoice->getClientEmail())
                ->replyTo(new Address($invoice->getUser()->getEmail(), $invoice->getUser()->getBusinessName() ?: ''))
                ->subject(sprintf('Receipt for %s from %s', $invoice->getNumber(), $invoice->getUser()->getBusinessName() ?: 'Settlepay'))
                ->htmlTemplate('emails/invoices/paid.html.twig')
                ->textTemplate('emails/invoices/paid.txt.twig')
                ->context($ctx);

            if ($pdfBytes !== null) {
                $clientEmail->attach($pdfBytes, $this->pdf->filenameFor($invoice), 'application/pdf');
            }
            try { $this->mailer->send($clientEmail); }
            catch (\Throwable $e) {
                $this->logger->error('Paid receipt email to client failed', [
                    'invoice_uuid' => $invoice->getUuid(),
                    'to_masked'    => $this->maskEmail($invoice->getClientEmail()),
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        // 2) Freelancer notification — always sent.
        $freelancerEmail = (new TemplatedEmail())
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($invoice->getUser()->getEmail())
            ->subject(sprintf('You got paid: %s — $%s %s', $invoice->getNumber(), $amountDecimal, $invoice->getCurrency()))
            ->htmlTemplate('emails/invoices/paid_freelancer.html.twig')
            ->textTemplate('emails/invoices/paid_freelancer.txt.twig')
            ->context($ctx);

        if ($pdfBytes !== null) {
            $freelancerEmail->attach($pdfBytes, $this->pdf->filenameFor($invoice), 'application/pdf');
        }
        try { $this->mailer->send($freelancerEmail); }
        catch (\Throwable $e) {
            $this->logger->error('Paid notification email to freelancer failed', [
                'invoice_uuid' => $invoice->getUuid(),
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build a block-explorer URL for the tx hash on the matching chain.
     * Returns null if the chain isn't in the registry (defensive — would
     * only happen if a chain was removed between payment and email send).
     */
    private function buildTxUrl(int $chainId, string $txHash): ?string
    {
        $cfg = $this->chains->getChainById($chainId);
        if (!$cfg || empty($cfg['explorer'])) {
            return null;
        }
        return rtrim($cfg['explorer'], '/') . '/tx/' . $txHash;
    }

    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 2) return '***';
        return substr($email, 0, 2) . str_repeat('*', max(0, $at - 2)) . substr($email, $at);
    }
}
