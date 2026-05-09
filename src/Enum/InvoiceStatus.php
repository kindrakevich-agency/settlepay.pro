<?php

namespace App\Enum;

enum InvoiceStatus: string
{
    case Draft         = 'draft';
    case Sent          = 'sent';
    case Viewed        = 'viewed';
    case Paid          = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Overdue       = 'overdue';
    case Void          = 'void';
    case Refunded      = 'refunded';

    /**
     * Statuses where the public payment page should still accept new payments.
     */
    public function isPayable(): bool
    {
        return match ($this) {
            self::Sent, self::Viewed, self::PartiallyPaid, self::Overdue => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Paid, self::Void, self::Refunded => true,
            default => false,
        };
    }
}
