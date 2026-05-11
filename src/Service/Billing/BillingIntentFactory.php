<?php

namespace App\Service\Billing;

use App\Entity\BillingIntent;
use App\Entity\User;
use App\Enum\BillingIntentKind;
use App\Repository\BillingIntentRepository;
use App\Service\Blockchain\ChainRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates a BillingIntent row for a given user + action. All pricing
 * lives here (single source of truth) so changing the Pro plan price is
 * one line.
 *
 *   pro_monthly    — $19 USDC
 *   pro_lifetime   — $299 USDC (one-time, ~15.7× monthly = ~16-month break-even)
 *   fee_settlement — user.fees_owed_cents
 *
 * accepted_chains/tokens default to all mainnets + USDC (we deliberately
 * don't accept USDT/DAI for billing — keeps reconciliation simple and
 * matches our own positioning that USDC is the canonical stablecoin).
 */
final class BillingIntentFactory
{
    public const PRO_MONTHLY_CENTS    = 1900;   // $19.00
    public const PRO_LIFETIME_CENTS   = 29900;  // $299.00
    public const AGENCY_MONTHLY_CENTS = 4900;   // $49.00 — 5 seats included

    public function __construct(
        private readonly BillingIntentRepository $intents,
        private readonly ChainRegistry $chains,
        private readonly PlatformWallet $platformWallet,
        /**
         * When true, billing intents also accept Sepolia testnet chains.
         * Default false — production must stay mainnet-only so nobody can
         * pay a $19 Pro subscription with free faucet USDC.
         *
         * Set BILLING_ALLOW_TESTNETS=1 in .env.local on dev / founder
         * servers to dogfood the upgrade flow with testnet USDC.
         */
        #[Autowire('%env(bool:default::BILLING_ALLOW_TESTNETS)%')]
        private readonly bool $allowTestnets = false,
    ) {}

    public function createProMonthly(User $user): BillingIntent
    {
        return $this->build($user, BillingIntentKind::ProMonthly, self::PRO_MONTHLY_CENTS);
    }

    public function createProLifetime(User $user): BillingIntent
    {
        return $this->build($user, BillingIntentKind::ProLifetime, self::PRO_LIFETIME_CENTS);
    }

    public function createAgencyMonthly(User $user): BillingIntent
    {
        return $this->build($user, BillingIntentKind::AgencyMonthly, self::AGENCY_MONTHLY_CENTS);
    }

    public function createFeeSettlement(User $user): ?BillingIntent
    {
        if ($user->getFeesOwedCents() <= 0) {
            return null;
        }
        return $this->build($user, BillingIntentKind::FeeSettlement, $user->getFeesOwedCents());
    }

    private function build(User $user, BillingIntentKind $kind, int $amountCents): BillingIntent
    {
        if (!$this->platformWallet->isEnabled()) {
            throw new \LogicException('PLATFORM_WALLET_ADDRESS is not configured — billing intent cannot be created.');
        }
        // Mainnet chain IDs always; testnets when explicitly enabled for dev dogfood.
        $sources = $this->allowTestnets
            ? ($this->chains->getMainnets() + $this->chains->getTestnets())
            : $this->chains->getMainnets();
        $chains = array_values(array_map(
            static fn(array $c): int => (int) $c['chain_id'],
            $sources
        ));

        $intent = (new BillingIntent())
            ->setUser($user)
            ->setKind($kind)
            ->setAmountCents($amountCents)
            ->setCurrency('USD')
            ->setAcceptedChains($chains)
            ->setAcceptedTokens(['USDC'])
            ->setRecipientAddress((string) $this->platformWallet->getAddress())
            // Lock the intent to the user's own payout wallet. This is what
            // lets the platform wallet ALSO be the user's payout wallet —
            // the listener will only match this billing intent when the
            // on-chain `from` is the user's wallet (self-payment), so
            // client-side invoice payments (different `from`) fall through
            // to the invoice flow. See BlockListener::tick().
            ->setExpectedPayerAddress($user->getPayoutAddress());

        $this->intents->save($intent);
        return $intent;
    }
}
