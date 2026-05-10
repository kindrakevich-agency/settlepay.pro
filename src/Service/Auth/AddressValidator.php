<?php

namespace App\Service\Auth;

/**
 * EVM wallet-address format validation.
 *
 *   - Must be 0x followed by exactly 40 hex chars
 *   - Stored lower-cased everywhere (CLAUDE.md security checklist)
 *   - We do NOT verify EIP-55 mixed-case checksums — typos at the
 *     wallet level have no security impact (the private key holder
 *     controls the funds at the lowercased address regardless), and
 *     EIP-55 verification needs Keccak-256 which is a heavy pure-PHP
 *     implementation. Defer until a real need surfaces.
 */
final class AddressValidator
{
    /**
     * @throws \InvalidArgumentException with a translation key
     *   ('errors.wallet_invalid_format') when format wrong.
     */
    public function normalize(string $address): string
    {
        $address = trim($address);
        if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            throw new \InvalidArgumentException('errors.wallet_invalid_format');
        }
        return strtolower($address);
    }

    public function isValid(string $address): bool
    {
        try { $this->normalize($address); return true; }
        catch (\InvalidArgumentException) { return false; }
    }
}
