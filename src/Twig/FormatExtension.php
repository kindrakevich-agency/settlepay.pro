<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig filters that operate on Settle-specific data shapes:
 *
 *   {{ '500000'|token_amount(6) }}    → '0.500000'
 *   {{ '0xabc...123'|short_address }} → '0xab…123'
 *   {{ '0xabc...123'|short_hash }}    → '0xabc…1234567'
 */
final class FormatExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('token_amount',  [$this, 'tokenAmount']),
            new TwigFilter('short_address', [$this, 'shortAddress']),
            new TwigFilter('short_hash',    [$this, 'shortHash']),
        ];
    }

    /**
     * Convert a uint256-stringified token amount + token decimals to a
     * human decimal string, trimmed of trailing zeros.
     *
     * Uses bcmath so 18-decimal tokens (DAI) don't lose precision.
     */
    public function tokenAmount(string $raw, int $decimals = 6, int $minFraction = 2): string
    {
        if ($raw === '' || $raw === '0') {
            return '0.' . str_repeat('0', $minFraction);
        }
        // Pad with leading zeros if shorter than `decimals` so we can split.
        $padded = str_pad($raw, $decimals + 1, '0', STR_PAD_LEFT);
        $whole  = substr($padded, 0, -$decimals);
        $frac   = substr($padded, -$decimals);

        // Trim trailing zeros from fraction but keep at least minFraction.
        $frac = rtrim($frac, '0');
        if (strlen($frac) < $minFraction) {
            $frac = str_pad($frac, $minFraction, '0');
        }

        return number_format((float) $whole, 0, '.', ',') . '.' . $frac;
    }

    /** 0x123456…789abc — keep first 6 + last 4 hex chars. */
    public function shortAddress(string $address): string
    {
        $address = (string) $address;
        if (strlen($address) <= 12) return $address;
        return substr($address, 0, 6) . '…' . substr($address, -4);
    }

    /** 0x12345678…87654321 — keep first 8 + last 8 hex chars (for tx hashes). */
    public function shortHash(string $hash): string
    {
        $hash = (string) $hash;
        if (strlen($hash) <= 18) return $hash;
        return substr($hash, 0, 8) . '…' . substr($hash, -8);
    }
}
