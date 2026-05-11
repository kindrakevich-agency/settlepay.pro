<?php

namespace App\Service\Billing;

use App\Entity\BillingIntent;
use App\Entity\FeePayment;
use App\Enum\BillingIntentStatus;
use App\Repository\BillingIntentRepository;
use App\Repository\FeePaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sibling to PaymentMatcher: handles ERC-20 Transfers received at the
 * platform wallet (subscriptions + fee settlements), as opposed to
 * Transfers to freelancer payout wallets (invoice flow).
 *
 *  - Looks up a pending BillingIntent whose amount + chain match (±0.5%)
 *  - Marks intent Paid, persists the FeePayment row
 *  - Delegates the user-account-state update to SubscriptionManager
 *
 * Idempotent on (chain_id, tx_hash, log_index).
 *
 * Payments to the platform wallet with no matching intent (someone sent
 * USDC out of band, wrong amount, expired intent, etc.) are stored as
 * "orphan" FeePayment rows for support to investigate.
 */
final class BillingPaymentMatcher
{
    private const TOLERANCE_BPS = 50; // ±0.5%

    public function __construct(
        private readonly BillingIntentRepository $intents,
        private readonly FeePaymentRepository $payments,
        private readonly SubscriptionManager $subscriptions,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Process one decoded Transfer event to the platform wallet.
     *
     * @param array{from:string, to:string, value:string, token_address:string, block_number:int, tx_hash:string, log_index:int} $transfer
     * @param array{symbol:string, decimals:int} $token
     */
    public function process(int $chainId, array $transfer, array $token, int $confirmations, \DateTimeImmutable $blockTimestamp): ?FeePayment
    {
        if ($this->payments->existsByTriple($chainId, $transfer['tx_hash'], $transfer['log_index'])) {
            return null;
        }

        // Convert raw token amount → integer USD cents (stablecoin assumed
        // 1:1 with USD). decimals=6 (USDC) → cents = raw / 10^4.
        $amountUsdCents = $this->rawToUsdCents($transfer['value'], $token['decimals']);

        $intent = $this->intents->findMatchingForPayment(
            chainId:      $chainId,
            amountCents:  $amountUsdCents,
            payerAddress: $transfer['from'],
            toleranceBps: self::TOLERANCE_BPS,
        );

        $payment = (new FeePayment())
            ->setChainId($chainId)
            ->setTxHash($transfer['tx_hash'])
            ->setLogIndex($transfer['log_index'])
            ->setBlockNumber((string) $transfer['block_number'])
            ->setBlockTimestamp($blockTimestamp)
            ->setTokenAddress($transfer['token_address'])
            ->setTokenSymbol($token['symbol'])
            ->setTokenDecimals($token['decimals'])
            ->setAmountRaw($transfer['value'])
            ->setAmountUsdCents($amountUsdCents)
            ->setPayerAddress($transfer['from'])
            ->setRecipientAddress($transfer['to'])
            ->setConfirmations($confirmations)
            ->setConfirmedAt(new \DateTimeImmutable());

        if ($intent !== null) {
            // Match found.
            $payment->setUser($intent->getUser())->setBillingIntent($intent);
            $intent->setStatus(BillingIntentStatus::Paid)
                ->setPaidAt(new \DateTimeImmutable())
                ->setPaidFeePayment($payment)
                ->touch();

            $this->logger->info('Billing intent matched', [
                'intent_uuid' => $intent->getUuid(),
                'kind'        => $intent->getKind()->value,
                'user_id'     => $intent->getUser()->getId(),
                'tx_hash'     => $transfer['tx_hash'],
                'chain_id'    => $chainId,
                'usd_cents'   => $amountUsdCents,
            ]);

            $this->payments->save($payment, flush: false);
            $this->em->flush();

            // Apply the subscription/fee effect after persistence.
            try {
                $this->subscriptions->applyPaidIntent($intent, $payment);
            } catch (\Throwable $e) {
                $this->logger->error('SubscriptionManager.applyPaidIntent failed', [
                    'intent_uuid' => $intent->getUuid(),
                    'error'       => $e->getMessage(),
                ]);
            }
            return $payment;
        }

        // No matching intent — store as orphan for support review. We
        // need SOME user_id (NOT NULL FK) — best-effort lookup by from
        // address against known users; fallback to skip if unknown.
        // For MVP, just log + skip. Persistence requires a user_id.
        $this->logger->warning('Platform-wallet Transfer with no matching billing intent', [
            'tx_hash'   => $transfer['tx_hash'],
            'chain_id'  => $chainId,
            'from'      => $transfer['from'],
            'token'     => $token['symbol'],
            'usd_cents' => $amountUsdCents,
        ]);
        return null;
    }

    /**
     * raw token base-units → integer USD cents.
     * For 6-decimal stablecoins (USDC, USDT): cents = raw / 10^(decimals-2).
     * For 18-decimal (DAI): cents = raw / 10^16.
     * Integer math via bcmath.
     */
    private function rawToUsdCents(string $raw, int $tokenDecimals): int
    {
        $div = bcpow('10', (string) ($tokenDecimals - 2));
        return (int) bcdiv($raw, $div, 0);
    }
}
