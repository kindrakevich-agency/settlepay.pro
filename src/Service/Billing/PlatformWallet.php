<?php

namespace App\Service\Billing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Wraps PLATFORM_WALLET_ADDRESS env var with normalization + a "is enabled"
 * check. The listener consults isEnabled() to decide whether to add the
 * platform wallet to its topic-filter recipients list.
 *
 * Same EVM address works across Base / Polygon / Arbitrum / Optimism, so
 * we keep this as a single string, not a per-chain map.
 */
final class PlatformWallet
{
    private readonly ?string $address;

    public function __construct(
        #[Autowire('%env(default::PLATFORM_WALLET_ADDRESS)%')] ?string $address = null,
    ) {
        $a = trim((string) $address);
        // Normalize to lowercase; reject obvious zero / placeholder addresses.
        if ($a === '' || preg_match('/^0x0+$/i', $a)) {
            $this->address = null;
            return;
        }
        // Format-only check (no EIP-55 verification — listener compares lowercase).
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $a)) {
            $this->address = null;
            return;
        }
        $this->address = strtolower($a);
    }

    public function isEnabled(): bool   { return $this->address !== null; }
    public function getAddress(): ?string { return $this->address; }
}
