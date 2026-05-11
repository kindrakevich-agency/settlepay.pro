<?php

namespace App\Controller\Api;

use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\User;
use App\Entity\Workspace;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Small helpers shared across the REST controllers. Kept as a final
 * non-instantiable utility — these are not domain services, just shaping
 * functions for response payloads.
 */
final class ApiResponse
{
    private function __construct() {}

    public static function ok(array $data, array $meta = [], int $status = 200): JsonResponse
    {
        $body = ['data' => $data];
        if ($meta) $body['meta'] = $meta;
        return new JsonResponse($body, $status);
    }

    public static function error(string $code, string $message, int $status = 400, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details) $error['details'] = $details;
        return new JsonResponse(['error' => $error], $status);
    }

    public static function userToArray(User $u, ?Workspace $workspace = null, bool $isOwner = false): array
    {
        return [
            'uuid'           => $u->getUuid(),
            'email'          => $u->getEmail(),
            'display_name'   => $u->getDisplayName(),
            'default_locale' => $u->getDefaultLocale(),
            'created_at'     => $u->getCreatedAt()->format(DATE_RFC3339),
            'workspace'      => $workspace ? self::workspaceToArray($workspace, $isOwner) : null,
        ];
    }

    public static function workspaceToArray(Workspace $w, bool $isOwner): array
    {
        return [
            'uuid'             => $w->getUuid(),
            'name'             => $w->getName(),
            'role'             => $isOwner ? 'owner' : 'member',
            'plan'             => $w->getPlan(),
            'is_pro'           => $w->isPro(),
            'is_agency'        => $w->isAgency(),
            'plan_renews_at'   => $w->getPlanRenewsAt()?->format(DATE_RFC3339),
            'fees_owed_cents'  => $w->getFeesOwedCents(),
            'business_name'    => $w->getBusinessName(),
            'business_address' => $w->getBusinessAddress(),
            'tax_id'           => $w->getTaxId(),
            'default_currency' => $w->getDefaultCurrency(),
            'payout_address'   => $w->getPayoutAddress(),
            'payout_chain_id'  => $w->getPayoutChainId(),
            'payout_token'     => $w->getPayoutToken(),
            'seat_limit'       => $w->getSeatLimit(),
        ];
    }

    public static function invoiceToArray(Invoice $i): array
    {
        $items = [];
        foreach ($i->getLineItems() as $li) {
            $items[] = [
                'description'     => $li->getDescription(),
                'quantity'        => $li->getQuantity(),
                'unit_price_cents'=> $li->getUnitPriceCents(),
                'total_cents'     => $li->getTotalCents(),
                'position'        => $li->getPosition(),
            ];
        }
        return [
            'uuid'              => $i->getUuid(),
            'number'            => $i->getNumber(),
            'status'            => $i->getStatus()->value,
            'amount_cents'      => $i->getAmountCents(),
            'currency'          => $i->getCurrency(),
            'client_name'       => $i->getClientName(),
            'client_email'      => $i->getClientEmail(),
            'client_address'    => $i->getClientAddress(),
            'description'       => $i->getDescription(),
            'notes'             => $i->getNotes(),
            'due_date'          => $i->getDueDate()?->format('Y-m-d'),
            'issued_at'         => $i->getIssuedAt()->format('Y-m-d'),
            'paid_at'           => $i->getPaidAt()?->format(DATE_RFC3339),
            'viewed_at'         => $i->getViewedAt()?->format(DATE_RFC3339),
            'recipient_address' => $i->getRecipientAddress(),
            'accepted_chains'   => $i->getAcceptedChains(),
            'accepted_tokens'   => $i->getAcceptedTokens(),
            'line_items'        => $items,
            'metadata'          => $i->getMetadata(),
            'created_at'        => $i->getCreatedAt()->format(DATE_RFC3339),
            'updated_at'        => $i->getUpdatedAt()->format(DATE_RFC3339),
            'created_by'        => $i->getUser()->getEmail(),
            'pay_url'           => sprintf('/pay/%s', $i->getUuid()),
        ];
    }

    public static function paymentToArray(Payment $p): array
    {
        return [
            'id'              => (int) $p->getId(),
            'invoice_uuid'    => $p->getInvoice()?->getUuid(),
            'chain_id'        => $p->getChainId(),
            'tx_hash'         => $p->getTxHash(),
            'log_index'       => $p->getLogIndex(),
            'block_number'    => $p->getBlockNumber(),
            'block_timestamp' => $p->getBlockTimestamp()->format(DATE_RFC3339),
            'token_address'   => $p->getTokenAddress(),
            'token_symbol'    => $p->getTokenSymbol(),
            'token_decimals'  => $p->getTokenDecimals(),
            'amount_raw'      => $p->getAmountRaw(),
            'amount_usd_cents'=> $p->getAmountUsdCents(),
            'payer_address'   => $p->getPayerAddress(),
            'recipient_address'=> $p->getRecipientAddress(),
            'confirmations'   => $p->getConfirmations(),
            'confirmed_at'    => $p->getConfirmedAt()?->format(DATE_RFC3339),
            'created_at'      => $p->getCreatedAt()->format(DATE_RFC3339),
        ];
    }
}
