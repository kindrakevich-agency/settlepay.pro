<?php

namespace App\Controller\Api;

use App\Entity\Invoice;
use App\Entity\User;
use App\Entity\Webhook;
use App\Enum\InvoiceStatus;
use App\Repository\InvoiceRepository;
use App\Service\Invoice\InvoiceFactory;
use App\Service\Invoice\InvoiceMailer;
use App\Service\Notification\WebhookDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/api/v1/invoices')]
class InvoiceController extends AbstractController
{
    private const UUID_REQ = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceFactory $factory,
        private readonly InvoiceMailer $mailer,
        private readonly EntityManagerInterface $em,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    #[Route('', name: 'api_invoices_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $userId = (int) $user->getId();

        $limit  = min(100, max(1, (int) $request->query->get('limit', 25)));
        $page   = max(1, (int) $request->query->get('page', 1));
        $status = (string) $request->query->get('status', '') ?: null;
        $search = (string) $request->query->get('q', '') ?: null;

        $items = $this->invoices->findByUserPaginated($userId, $page, $limit, $status, $search);
        $total = $this->invoices->countByUser($userId, $status, $search);

        return ApiResponse::ok(
            array_map([ApiResponse::class, 'invoiceToArray'], $items),
            ['page' => $page, 'limit' => $limit, 'total' => $total],
        );
    }

    #[Route('', name: 'api_invoices_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->getPayoutAddress() === '0x0000000000000000000000000000000000000000') {
            return ApiResponse::error('payout.unset', 'Set a payout wallet before creating invoices.', 422);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return ApiResponse::error('request.invalid_json', 'Body must be JSON.', 400);
        }
        if (empty($body['client_name'])) {
            return ApiResponse::error('invoice.missing_client_name', 'client_name is required.', 422);
        }

        // Accept either {line_items: [...]} OR a flat {amount_cents,description}.
        if (empty($body['line_items']) && isset($body['amount_cents'])) {
            $body['line_items'] = [[
                'description'      => (string) ($body['description'] ?? $body['client_name']),
                'quantity'         => '1.00',
                'unit_price_cents' => (int) $body['amount_cents'],
            ]];
        }
        if (empty($body['line_items'])) {
            return ApiResponse::error('invoice.missing_line_items', 'At least one line_item or amount_cents is required.', 422);
        }

        try {
            $invoice = $this->factory->create($user, $body);
        } catch (\Throwable $e) {
            return ApiResponse::error('invoice.create_failed', $e->getMessage(), 422);
        }

        // Optional: ?send=1 or {"send": true} → also dispatch the email + invoice.sent webhook.
        $autoSend = (bool) ($body['send'] ?? $request->query->getBoolean('send'));
        if ($autoSend) {
            $invoice->setStatus(InvoiceStatus::Sent)->touch();
            $this->em->flush();
            try {
                $this->webhooks->dispatch($user, Webhook::EVENT_INVOICE_SENT, ['invoice' => ApiResponse::invoiceToArray($invoice)]);
            } catch (\Throwable) { /* non-fatal */ }
            $this->mailer->sendInvoiceToClient($invoice);
        }

        return ApiResponse::ok(ApiResponse::invoiceToArray($invoice), [], 201);
    }

    #[Route('/{uuid}', name: 'api_invoices_get', methods: ['GET'], requirements: ['uuid' => self::UUID_REQ])]
    public function get(string $uuid): JsonResponse
    {
        $invoice = $this->fetchOwnedOrFail($uuid);
        if ($invoice instanceof JsonResponse) return $invoice;
        return ApiResponse::ok(ApiResponse::invoiceToArray($invoice));
    }

    #[Route('/{uuid}', name: 'api_invoices_patch', methods: ['PATCH'], requirements: ['uuid' => self::UUID_REQ])]
    public function patch(string $uuid, Request $request): JsonResponse
    {
        $invoice = $this->fetchOwnedOrFail($uuid);
        if ($invoice instanceof JsonResponse) return $invoice;

        if ($invoice->getStatus() !== InvoiceStatus::Draft) {
            return ApiResponse::error('invoice.not_editable', 'Only drafts can be edited.', 409);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return ApiResponse::error('request.invalid_json', 'Body must be JSON.', 400);
        }
        try {
            $this->factory->update($invoice, $body + [
                'client_name' => $invoice->getClientName(),
                'line_items'  => $body['line_items'] ?? array_map(fn ($li) => [
                    'description'      => $li->getDescription(),
                    'quantity'         => $li->getQuantity(),
                    'unit_price_cents' => $li->getUnitPriceCents(),
                ], $invoice->getLineItems()->toArray()),
            ]);
        } catch (\Throwable $e) {
            return ApiResponse::error('invoice.update_failed', $e->getMessage(), 422);
        }
        return ApiResponse::ok(ApiResponse::invoiceToArray($invoice));
    }

    #[Route('/{uuid}', name: 'api_invoices_delete', methods: ['DELETE'], requirements: ['uuid' => self::UUID_REQ])]
    public function delete(string $uuid): JsonResponse
    {
        $invoice = $this->fetchOwnedOrFail($uuid);
        if ($invoice instanceof JsonResponse) return $invoice;

        if ($invoice->getStatus() !== InvoiceStatus::Draft) {
            return ApiResponse::error('invoice.not_deletable', 'Only drafts can be deleted. Use /void for sent invoices.', 409);
        }
        $this->em->remove($invoice);
        $this->em->flush();
        return new JsonResponse(null, 204);
    }

    #[Route('/{uuid}/send', name: 'api_invoices_send', methods: ['POST'], requirements: ['uuid' => self::UUID_REQ])]
    public function send(string $uuid): JsonResponse
    {
        $invoice = $this->fetchOwnedOrFail($uuid);
        if ($invoice instanceof JsonResponse) return $invoice;

        if ($invoice->getStatus() !== InvoiceStatus::Draft) {
            return ApiResponse::error('invoice.already_sent', 'Invoice has already been sent.', 409);
        }
        $invoice->setStatus(InvoiceStatus::Sent)->touch();
        $this->em->flush();

        try {
            $this->webhooks->dispatch($invoice->getUser(), Webhook::EVENT_INVOICE_SENT, ['invoice' => ApiResponse::invoiceToArray($invoice)]);
        } catch (\Throwable) { /* non-fatal */ }

        $emailed = $this->mailer->sendInvoiceToClient($invoice);
        return ApiResponse::ok(ApiResponse::invoiceToArray($invoice), ['emailed' => $emailed]);
    }

    #[Route('/{uuid}/void', name: 'api_invoices_void', methods: ['POST'], requirements: ['uuid' => self::UUID_REQ])]
    public function void(string $uuid): JsonResponse
    {
        $invoice = $this->fetchOwnedOrFail($uuid);
        if ($invoice instanceof JsonResponse) return $invoice;

        if (!in_array($invoice->getStatus(), [InvoiceStatus::Draft, InvoiceStatus::Sent, InvoiceStatus::Viewed], true)) {
            return ApiResponse::error('invoice.cannot_void', 'Paid invoices cannot be voided.', 409);
        }
        $invoice->setStatus(InvoiceStatus::Void)->touch();
        $this->em->flush();

        try {
            $this->webhooks->dispatch($invoice->getUser(), Webhook::EVENT_INVOICE_VOIDED, ['invoice' => ApiResponse::invoiceToArray($invoice)]);
        } catch (\Throwable) { /* non-fatal */ }

        return ApiResponse::ok(ApiResponse::invoiceToArray($invoice));
    }

    /** Returns the Invoice if owned by the caller, otherwise an error JsonResponse. */
    private function fetchOwnedOrFail(string $uuid): Invoice|JsonResponse
    {
        $invoice = $this->invoices->findByUuid($uuid);
        if (!$invoice) {
            return ApiResponse::error('invoice.not_found', 'Invoice not found.', 404);
        }
        if ($invoice->getUser()->getId() !== $this->getUser()->getId()) {
            return ApiResponse::error('invoice.not_found', 'Invoice not found.', 404);
        }
        return $invoice;
    }
}
