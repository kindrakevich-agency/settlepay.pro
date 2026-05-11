<?php

namespace App\Service\Billing;

use App\Entity\BillingIntent;
use App\Entity\FeePayment;
use App\Entity\Invoice;
use App\Entity\User;
use App\Enum\BillingIntentKind;
use App\Enum\BillingIntentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies the side effects of a successful billing payment on the user's
 * account: extend Pro plan, mark lifetime, or clear accumulated fees.
 *
 * Called from BillingPaymentMatcher *after* the FeePayment row is saved
 * and the BillingIntent has been marked Paid.
 *
 * Also exposes accrueFee() — invoked by PaymentMatcher whenever a
 * freelancer's invoice gets matched, so per-paid-invoice fees accumulate
 * on users.fees_owed_cents.
 */
final class SubscriptionManager
{
    /** Pro plan extension period when a pro_monthly payment is received. */
    private const MONTHLY_PERIOD = 'P30D';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Apply the effect of a paid intent on the user account.
     * The caller has already linked $payment to $intent and flipped
     * $intent status to Paid.
     */
    public function applyPaidIntent(BillingIntent $intent, FeePayment $payment): void
    {
        $user = $intent->getUser();

        switch ($intent->getKind()) {
            case BillingIntentKind::ProMonthly:
                // Start from max(now, current renewsAt) so a user who renews
                // early gets the full 30 days added, not just to "now + 30".
                $base = $user->getPlanRenewsAt();
                $start = ($base !== null && $base > new \DateTimeImmutable())
                    ? \DateTimeImmutable::createFromInterface($base)
                    : new \DateTimeImmutable();
                $user->setPlan('pro')
                    ->setPlanRenewsAt($start->add(new \DateInterval(self::MONTHLY_PERIOD)))
                    ->setPlanCanceledAt(null);
                foreach ($user->getOwnedWorkspaces() as $w) {
                    $w->setPlan('pro')->setPlanRenewsAt($user->getPlanRenewsAt())->setPlanCanceledAt(null)->touch();
                }
                $this->logger->info('Pro plan extended', [
                    'user_id'    => $user->getId(),
                    'renews_at'  => $user->getPlanRenewsAt()?->format(\DATE_ATOM),
                    'intent_uuid'=> $intent->getUuid(),
                ]);
                break;

            case BillingIntentKind::ProLifetime:
                $user->setPlan('pro')
                    ->setPlanRenewsAt(null)
                    ->setPlanCanceledAt(null);
                foreach ($user->getOwnedWorkspaces() as $w) {
                    $w->setPlan('pro')->setPlanRenewsAt(null)->setPlanCanceledAt(null)->touch();
                }
                $this->logger->info('Pro lifetime activated', [
                    'user_id'    => $user->getId(),
                    'intent_uuid'=> $intent->getUuid(),
                ]);
                break;

            case BillingIntentKind::AgencyMonthly:
                // Same renewal logic as Pro monthly, plus bumps the workspace
                // seat_limit to 5 so the owner can immediately invite teammates.
                $base = $user->getPlanRenewsAt();
                $start = ($base !== null && $base > new \DateTimeImmutable())
                    ? \DateTimeImmutable::createFromInterface($base)
                    : new \DateTimeImmutable();
                $user->setPlan('agency')
                    ->setPlanRenewsAt($start->add(new \DateInterval(self::MONTHLY_PERIOD)))
                    ->setPlanCanceledAt(null);

                // Mirror onto the user's owned workspace(s). Phase 1 keeps
                // user.plan as the source of truth, but workspace.plan +
                // seat_limit drive seat enforcement, so they MUST agree.
                foreach ($user->getOwnedWorkspaces() as $workspace) {
                    $workspace->setPlan('agency')
                        ->setPlanRenewsAt($user->getPlanRenewsAt())
                        ->setPlanCanceledAt(null)
                        ->setSeatLimit(5)
                        ->touch();
                }
                $this->logger->info('Agency plan extended', [
                    'user_id'    => $user->getId(),
                    'renews_at'  => $user->getPlanRenewsAt()?->format(\DATE_ATOM),
                    'intent_uuid'=> $intent->getUuid(),
                ]);
                break;

            case BillingIntentKind::FeeSettlement:
                // Reduce fees_owed_cents by the amount paid. Clamp at 0 so
                // overpayments don't create a negative balance.
                $applied = min($user->getFeesOwedCents(), $intent->getAmountCents());
                $user->setFeesOwedCents(max(0, $user->getFeesOwedCents() - $applied));
                $this->logger->info('Fees settled', [
                    'user_id'      => $user->getId(),
                    'applied_cents'=> $applied,
                    'remaining'    => $user->getFeesOwedCents(),
                    'intent_uuid'  => $intent->getUuid(),
                ]);
                break;
        }

        $this->em->flush();
    }

    /**
     * Add the per-invoice percentage fee to the freelancer's owed-balance
     * when an invoice is matched. Called from PaymentMatcher right after
     * the invoice status flip.
     *
     * Free plan: 1% (100 bps). Pro: 0.5% (50 bps).
     */
    public function accrueInvoiceFee(Invoice $invoice): int
    {
        $user = $invoice->getUser();
        $rateBps = $user->feeRateBps();
        // floor(amount * bps / 10000). Integer math throughout.
        $feeCents = intdiv($invoice->getAmountCents() * $rateBps, 10000);
        if ($feeCents <= 0) {
            return 0;
        }
        $user->addFeesOwedCents($feeCents);
        $this->em->flush();
        $this->logger->info('Per-invoice fee accrued', [
            'user_id'       => $user->getId(),
            'invoice_no'    => $invoice->getNumber(),
            'fee_cents'     => $feeCents,
            'new_owed_cents'=> $user->getFeesOwedCents(),
            'plan'          => $user->getPlan(),
        ]);
        return $feeCents;
    }
}
