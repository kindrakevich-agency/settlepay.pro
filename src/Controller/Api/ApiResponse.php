<?php

namespace App\Controller\Api;

use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\User;
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

    public static function userToArray(User $u): array
    {
        return [
            'uuid'             => $u->getUuid(),
            'email'            => $u->getEmail(),
            'display_name'     => $u->getDisplayName(),
            'business_name'    => $u->getBusinessName(),
            'business_address' => $u->getBusinessAddress(),
            'tax_id'           => $u->getTaxId(),
            'default_currency' => $u->getDefaultCurrency(),
            'default_locale'   => $u->getDefaultLocale(),
            'payout_address'   => $u->getPayoutAddress(),
            'payout_chain_id'  => $u->getPayoutChainId(),
            'payout_token'     => $u->getPayoutToken(),
            'plan'             => $u->getPlan(),
            'is_pro'           => $u->isPro(),
            'plan_renews_at'   => $u->getPlanRenewsAt()?->format(DATE_RFC3339),
            'fees_owed_cents'  => $u->getFeesOwedCents(),
            'created_at'       => $u->getCreatedAt()->format(DATE_RFC3339),
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
