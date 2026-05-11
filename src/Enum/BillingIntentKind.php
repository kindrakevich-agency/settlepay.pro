<?php

namespace App\Enum;

/**
 * What the freelancer is paying for when they click "Pay".
 *
 *  - pro_monthly     — $19 USDC, extends Pro plan by 30 days
 *  - pro_lifetime    — $299 USDC one-time, sets Pro forever (plan_renews_at = NULL = no expiry)
 *  - fee_settlement  — pay down accumulated 1%/0.5% per-invoice fees
 */
enum BillingIntentKind: string
{
    case ProMonthly    = 'pro_monthly';
    case ProLifetime   = 'pro_lifetime';
    case AgencyMonthly = 'agency_monthly';   // $49 USDC, 5 seats, 30 days
    case FeeSettlement = 'fee_settlement';

    public function isSubscription(): bool
    {
        return $this === self::ProMonthly
            || $this === self::ProLifetime
            || $this === self::AgencyMonthly;
    }

    public function planSlug(): ?string
    {
        return match ($this) {
            self::ProMonthly, self::ProLifetime => 'pro',
            self::AgencyMonthly                 => 'agency',
            default                             => null,
        };
    }
}
