<?php

namespace App\Service\Billing;

use App\Entity\BillingIntent;
use App\Entity\FeePayment;
use App\Entity\Invoice;
use App\Enum\BillingIntentKind;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies the side effects of a successful billing payment on the
 * workspace: extend Pro/Agency plan, mark lifetime, or clear
 * accumulated fees.
 *
 * Called from BillingPaymentMatcher *after* the FeePayment row is saved
 * and the BillingIntent has been marked Paid.
 *
 * Also exposes accrueInvoiceFee() — invoked by PaymentMatcher whenever
 * an invoice gets matched, so per-paid-invoice fees accumulate on the
 * workspace.fees_owed_cents.
 */
final class SubscriptionManager
{
    private const MONTHLY_PERIOD = 'P30D';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Apply the effect of a paid intent on the workspace.
     * The caller has already linked $payment to $intent and flipped
     * $intent status to Paid.
     */
    public function applyPaidIntent(BillingIntent $intent, FeePayment $payment): void
    {
        $workspace = $intent->getWorkspace();
        if (!$workspace) {
            $this->logger->error('BillingIntent has no workspace — cannot apply', [
                'intent_uuid' => $intent->getUuid(),
            ]);
            return;
        }

        switch ($intent->getKind()) {
            case BillingIntentKind::ProMonthly:
                $base  = $workspace->getPlanRenewsAt();
                $start = ($base !== null && $base > new \DateTimeImmutable())
                    ? \DateTimeImmutable::createFromInterface($base)
                    : new \DateTimeImmutable();
                $workspace->setPlan('pro')
                    ->setPlanRenewsAt($start->add(new \DateInterval(self::MONTHLY_PERIOD)))
                    ->setPlanCanceledAt(null)
                    ->touch();
                $this->logger->info('Pro plan extended', [
                    'workspace_id' => $workspace->getId(),
                    'renews_at'    => $workspace->getPlanRenewsAt()?->format(\DATE_ATOM),
                    'intent_uuid'  => $intent->getUuid(),
                ]);
                break;

            case BillingIntentKind::ProLifetime:
                $workspace->setPlan('pro')
                    ->setPlanRenewsAt(null)
                    ->setPlanCanceledAt(null)
                    ->touch();
                $this->logger->info('Pro lifetime activated', [
                    'workspace_id' => $workspace->getId(),
                    'intent_uuid'  => $intent->getUuid(),
                ]);
                break;

            case BillingIntentKind::AgencyMonthly:
                $base  = $workspace->getPlanRenewsAt();
                $start = ($base !== null && $base > new \DateTimeImmutable())
                    ? \DateTimeImmutable::createFromInterface($base)
                    : new \DateTimeImmutable();
                $workspace->setPlan('agency')
                    ->setPlanRenewsAt($start->add(new \DateInterval(self::MONTHLY_PERIOD)))
                    ->setPlanCanceledAt(null)
                    ->setSeatLimit(5)
                    ->touch();
                $this->logger->info('Agency plan extended', [
                    'workspace_id' => $workspace->getId(),
                    'renews_at'    => $workspace->getPlanRenewsAt()?->format(\DATE_ATOM),
                    'intent_uuid'  => $intent->getUuid(),
                ]);
                break;

            case BillingIntentKind::FeeSettlement:
                $applied = min($workspace->getFeesOwedCents(), $intent->getAmountCents());
                $workspace->setFeesOwedCents(max(0, $workspace->getFeesOwedCents() - $applied))->touch();
                $this->logger->info('Fees settled', [
                    'workspace_id'  => $workspace->getId(),
                    'applied_cents' => $applied,
                    'remaining'     => $workspace->getFeesOwedCents(),
                    'intent_uuid'   => $intent->getUuid(),
                ]);
                break;
        }

        $this->em->flush();
    }

    /**
     * Add the per-invoice percentage fee to the workspace's owed-balance
     * when an invoice is matched. Free plan: 1% (100 bps). Pro/Agency: 0.5% (50 bps).
     */
    public function accrueInvoiceFee(Invoice $invoice): int
    {
        $workspace = $invoice->getWorkspace();
        if (!$workspace) {
            $this->logger->warning('Invoice has no workspace; cannot accrue fee', [
                'invoice_uuid' => $invoice->getUuid(),
            ]);
            return 0;
        }
        $rateBps  = $workspace->feeRateBps();
        $feeCents = intdiv($invoice->getAmountCents() * $rateBps, 10000);
        if ($feeCents <= 0) {
            return 0;
        }
        $workspace->addFeesOwedCents($feeCents)->touch();
        $this->em->flush();
        $this->logger->info('Per-invoice fee accrued', [
            'workspace_id'  => $workspace->getId(),
            'invoice_no'    => $invoice->getNumber(),
            'fee_cents'     => $feeCents,
            'new_owed_cents'=> $workspace->getFeesOwedCents(),
            'plan'          => $workspace->getPlan(),
        ]);
        return $feeCents;
    }
}
