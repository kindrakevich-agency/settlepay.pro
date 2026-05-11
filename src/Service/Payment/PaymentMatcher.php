<?php

namespace App\Service\Payment;

use App\Entity\Invoice;
use App\Entity\Payment;
use App\Enum\InvoiceStatus;
use App\Repository\InvoiceRepository;
use App\Entity\Webhook;
use App\Repository\PaymentRepository;
use App\Service\Billing\SubscriptionManager;
use App\Service\Invoice\InvoiceMailer;
use App\Service\Notification\WebhookDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Matches a confirmed on-chain Transfer to an open invoice and records
 * the resulting Payment row + status transition.
 *
 *   - Token allowlist enforcement (caller must already have looked up
 *     the token by contract address; we re-check by symbol/decimals here)
 *   - ±0.5% amount tolerance per CLAUDE.md
 *   - Idempotent — re-matching the same (chain, tx, log) is a no-op
 *   - Multiple open invoices for the same recipient: pick the oldest
 *     whose expected amount matches within tolerance
 */
final class PaymentMatcher
{
    /** ±0.5% — exchange-rate noise on stablecoins is typically <0.05%, this leaves headroom. */
    private const TOLERANCE_BPS = 50;

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly PaymentRepository $payments,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly InvoiceMailer $mailer,
        private readonly SubscriptionManager $subscriptions,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    /**
     * Process one decoded Transfer event.
     *
     * @param array{
     *   from:string, to:string, value:string,
     *   token_address:string, block_number:int, tx_hash:string, log_index:int
     * } $transfer
     * @param array{symbol:string, decimals:int} $token
     */
    public function process(int $chainId, array $transfer, array $token, int $confirmations, \DateTimeImmutable $blockTimestamp): ?Payment
    {
        // Idempotency — already processed this exact log.
        if ($this->payments->existsByTriple($chainId, $transfer['tx_hash'], $transfer['log_index'])) {
            return null;
        }

        $payment = (new Payment())
            ->setChainId($chainId)
            ->setTxHash($transfer['tx_hash'])
            ->setLogIndex($transfer['log_index'])
            ->setBlockNumber((string) $transfer['block_number'])
            ->setBlockTimestamp($blockTimestamp)
            ->setTokenAddress($transfer['token_address'])
            ->setTokenSymbol($token['symbol'])
            ->setTokenDecimals($token['decimals'])
            ->setAmountRaw($transfer['value'])
            ->setPayerAddress($transfer['from'])
            ->setRecipientAddress($transfer['to'])
            ->setConfirmations($confirmations)
            ->setConfirmedAt(new \DateTimeImmutable());

        // Try to match against an open invoice.
        $candidates = $this->invoices->findOpenByRecipient($transfer['to']);
        $matched = $this->pickMatchingInvoice($candidates, $transfer['value'], $token['decimals']);

        if ($matched !== null) {
            $payment->setInvoice($matched);
            $payment->setAmountUsdCents($this->amountToUsdCents($transfer['value'], $token['decimals'], $token['symbol']));
            $matched
                ->setStatus(InvoiceStatus::Paid)
                ->setPaidAt(new \DateTimeImmutable())
                ->touch();

            $this->logger->info('Invoice matched to payment', [
                'invoice_uuid' => $matched->getUuid(),
                'invoice_no'   => $matched->getNumber(),
                'tx_hash'      => $transfer['tx_hash'],
                'chain_id'     => $chainId,
                'amount_raw'   => $transfer['value'],
                'token'        => $token['symbol'],
            ]);
        } else {
            $this->logger->warning('On-chain Transfer with no matching invoice (orphan)', [
                'tx_hash'   => $transfer['tx_hash'],
                'chain_id'  => $chainId,
                'recipient' => $transfer['to'],
                'amount'    => $transfer['value'],
                'token'     => $token['symbol'],
            ]);
        }

        $this->payments->save($payment, flush: false);
        $this->em->flush();

        // Now that the row is persisted, fire receipt + notification emails.
        // Email failure is non-fatal — already logged inside the mailer.
        // Only sends on a successful match (orphans get no emails).
        if ($matched !== null) {
            try {
                $this->mailer->sendPaidNotifications($matched, $payment);
            } catch (\Throwable $e) {
                $this->logger->error('Paid-notification dispatch failed', [
                    'invoice_uuid' => $matched->getUuid(),
                    'error'        => $e->getMessage(),
                ]);
            }
            // Accumulate the freelancer's owed platform fee (1% Free, 0.5% Pro).
            // The freelancer pays it down via a fee_settlement BillingIntent
            // separately. Failure is non-fatal — already logged inside.
            try {
                $this->subscriptions->accrueInvoiceFee($matched);
            } catch (\Throwable $e) {
                $this->logger->error('accrueInvoiceFee failed', [
                    'invoice_uuid' => $matched->getUuid(),
                    'error'        => $e->getMessage(),
                ]);
            }
            // Fire Pro-tier webhooks (invoice.paid + payment.received) —
            // dispatching enqueues, so failure here can't break the match.
            // Phase 2: dispatch is workspace-scoped.
            try {
                $workspace = $matched->getWorkspace();
                if (!$workspace) {
                    // Defensive: pre-Phase-2 rows may exist without workspace yet.
                    throw new \LogicException('Invoice without workspace; skipping webhook');
                }
                $this->webhooks->dispatch($workspace, Webhook::EVENT_INVOICE_PAID, [
                    'invoice' => [
                        'uuid'         => $matched->getUuid(),
                        'number'       => $matched->getNumber(),
                        'amount_cents' => $matched->getAmountCents(),
                        'currency'     => $matched->getCurrency(),
                        'status'       => $matched->getStatus()->value,
                        'paid_at'      => $matched->getPaidAt()?->format(DATE_RFC3339),
                    ],
                ]);
                $this->webhooks->dispatch($workspace, Webhook::EVENT_PAYMENT_RECEIVED, [
                    'payment' => [
                        'id'            => (int) $payment->getId(),
                        'invoice_uuid'  => $matched->getUuid(),
                        'chain_id'      => $payment->getChainId(),
                        'tx_hash'       => $payment->getTxHash(),
                        'token_symbol'  => $payment->getTokenSymbol(),
                        'token_decimals'=> $payment->getTokenDecimals(),
                        'amount_raw'    => $payment->getAmountRaw(),
                        'amount_usd_cents' => $payment->getAmountUsdCents(),
                        'payer_address' => $payment->getPayerAddress(),
                        'confirmed_at'  => $payment->getConfirmedAt()?->format(DATE_RFC3339),
                    ],
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Webhook dispatch failed', [
                    'invoice_uuid' => $matched->getUuid(),
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        return $payment;
    }

    /**
     * Pick the open invoice whose expected amount best matches the
     * received raw amount within the configured tolerance.
     *
     * @param Invoice[] $candidates
     */
    private function pickMatchingInvoice(array $candidates, string $rawValue, int $tokenDecimals): ?Invoice
    {
        foreach ($candidates as $invoice) {
            // Convert invoice expected amount (cents, integer) into the
            // token's base units. Stablecoins are 1:1 with USD so:
            //   base_units_expected = amount_cents * 10^(decimals - 2)
            $expected = bcmul((string) $invoice->getAmountCents(), bcpow('10', (string) ($tokenDecimals - 2)));
            if ($this->withinTolerance($rawValue, $expected, self::TOLERANCE_BPS)) {
                return $invoice;
            }
        }
        return null;
    }

    /** True if |actual - expected| / expected ≤ toleranceBps / 10000 */
    private function withinTolerance(string $actual, string $expected, int $toleranceBps): bool
    {
        if (bccomp($expected, '0') === 0) return false;
        $diff      = bcsub($actual, $expected);
        if ($diff[0] === '-') $diff = substr($diff, 1);
        $maxDiff   = bcdiv(bcmul($expected, (string) $toleranceBps), '10000');
        return bccomp($diff, $maxDiff) <= 0;
    }

    /**
     * Snapshot of how many USD cents this payment represented at confirmation.
     * Stablecoins (USDC/USDT/DAI) are pegged to USD, so we treat them as 1:1.
     * Phase 2: pull a real CoinGecko price if we ever accept non-stables.
     */
    private function amountToUsdCents(string $rawValue, int $tokenDecimals, string $symbol): ?int
    {
        if (!in_array($symbol, ['USDC', 'USDT', 'DAI'], true)) {
            return null;
        }
        // base_units / 10^(decimals - 2) = cents
        $cents = bcdiv($rawValue, bcpow('10', (string) ($tokenDecimals - 2)), 0);
        return (int) $cents;
    }
}
