<?php

namespace App\Controller\Api;

use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Public, unauthenticated invoice surface used by the checkout page JS.
 *
 *   GET  /api/v1/public/invoices/{uuid}            - poll invoice status
 *   POST /api/v1/public/invoices/{uuid}/track-view - mark as viewed
 *   POST /api/v1/public/invoices/{uuid}/tx         - report client-side tx hash
 *
 * Returns LIMITED data — never user PII beyond business_name, never
 * the full client_email of the recipient party, never internal metadata.
 */
#[Route('/api/v1/public/invoices/{uuid}', requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'])]
class PublicInvoiceController extends AbstractController
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly EntityManagerInterface $em,
        private readonly RateLimiterFactory $publicInvoiceLimiter,
    ) {}

    #[Route('', name: 'api_public_invoice_show', methods: ['GET'])]
    public function show(string $uuid, Request $request): JsonResponse
    {
        $this->limit($request);

        $invoice = $this->invoices->findByUuid($uuid);
        if (!$invoice) {
            return $this->json(['error' => ['code' => 'invoice.not_found', 'message' => 'Invoice not found']], 404);
        }

        return $this->json([
            'data' => [
                'uuid'              => $invoice->getUuid(),
                'number'            => $invoice->getNumber(),
                'status'            => $invoice->getStatus()->value,
                'amount_cents'      => $invoice->getAmountCents(),
                'currency'          => $invoice->getCurrency(),
                'recipient_address' => $invoice->getRecipientAddress(),
                'accepted_chains'   => $invoice->getAcceptedChains(),
                'accepted_tokens'   => $invoice->getAcceptedTokens(),
                'paid_at'           => $invoice->getPaidAt()?->format(\DateTimeInterface::ATOM),
                'business_name'     => $invoice->getUser()->getBusinessName(),
            ],
        ]);
    }

    #[Route('/track-view', name: 'api_public_invoice_track_view', methods: ['POST'])]
    public function trackView(string $uuid, Request $request): JsonResponse
    {
        $this->limit($request);

        $invoice = $this->invoices->findByUuid($uuid);
        if (!$invoice) {
            return $this->json(['error' => ['code' => 'invoice.not_found', 'message' => 'Invoice not found']], 404);
        }

        if ($invoice->markViewed()) {
            $this->em->flush();
        }
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/tx', name: 'api_public_invoice_report_tx', methods: ['POST'])]
    public function reportTx(string $uuid, Request $request): JsonResponse
    {
        $this->limit($request);

        $invoice = $this->invoices->findByUuid($uuid);
        if (!$invoice) {
            return $this->json(['error' => ['code' => 'invoice.not_found', 'message' => 'Invoice not found']], 404);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $hash = is_string($body['tx_hash'] ?? null) ? strtolower($body['tx_hash']) : null;
        if (!$hash || !preg_match('/^0x[0-9a-f]{64}$/', $hash)) {
            return $this->json(['error' => ['code' => 'tx.invalid_hash', 'message' => 'tx_hash must be a 0x-prefixed 32-byte hex string']], 422);
        }

        // Stage 3 will plumb this into the listener as a fast-path detection
        // hint; for now we just stash it on metadata so the dashboard / logs
        // can show the client-reported hash before the listener confirms.
        $meta = $invoice->getMetadata() ?? [];
        $meta['client_reported_tx'] = ['hash' => $hash, 'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)];
        $invoice->setMetadata($meta);
        $invoice->touch();
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_ACCEPTED);
    }

    private function limit(Request $request): void
    {
        $limiter = $this->publicInvoiceLimiter->create($request->getClientIp() ?? 'anon');
        if (!$limiter->consume()->isAccepted()) {
            throw new \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException(60);
        }
    }
}
