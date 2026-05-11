<?php

namespace App\Service\Invoice;

use App\Entity\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Renders an Invoice entity into a PDF byte string.
 *
 * Uses dompdf (pure PHP, no system binaries) — good enough for invoice-
 * shape documents with text + light styling. The Twig template lives at
 * templates/pdf/invoice.html.twig.
 *
 * For paid invoices the template flips to a "Receipt" header and
 * includes the on-chain tx hash + payment timestamp. Otherwise it's
 * a "professional invoice" suitable to attach to outgoing email.
 */
final class InvoicePdfRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    /**
     * @return string Raw PDF bytes. Caller writes to a Response with
     *                Content-Type: application/pdf.
     */
    public function render(Invoice $invoice, string $locale = 'en'): string
    {
        $opts = new Options();
        $opts->set('defaultFont', 'DejaVu Sans');  // ships with dompdf, has full Cyrillic + Latin coverage
        $opts->set('isRemoteEnabled', false);       // no remote images / SSRF surface
        $opts->set('chroot', \dirname(__DIR__, 3) . '/public');

        $dompdf = new Dompdf($opts);
        $html   = $this->twig->render('pdf/invoice.html.twig', [
            'invoice' => $invoice,
            'user'    => $invoice->getUser(),
            'locale'  => $locale,
        ]);

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Build the Content-Disposition filename for downloads.
     * "INV-2026-0042.pdf" → easy to file in client's accounting folder.
     */
    public function filenameFor(Invoice $invoice): string
    {
        return $invoice->getNumber() . '.pdf';
    }
}
