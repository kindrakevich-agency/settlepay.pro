<?php

namespace App\Enum;

/**
 * Lifecycle of a billing payment request:
 *
 *  pending  — created, waiting for the freelancer to pay
 *  paid     — on-chain Transfer matched, user account credited
 *  expired  — TTL elapsed (default 1 hour) without payment
 */
enum BillingIntentStatus: string
{
    case Pending = 'pending';
    case Paid    = 'paid';
    case Expired = 'expired';
}
