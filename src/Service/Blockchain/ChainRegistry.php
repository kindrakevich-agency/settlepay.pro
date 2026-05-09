<?php

namespace App\Service\Blockchain;

use Symfony\Component\Yaml\Yaml;

/**
 * Source-of-truth for chain & token configuration.
 *
 * Loads config/chains.yaml and config/tokens.yaml at boot.
 * Used by:
 *   - the public payment page (which chains/tokens to render)
 *   - the chain listener (which contracts to listen for)
 *   - PaymentMatcher (which incoming Transfer events to credit)
 */
final class ChainRegistry
{
    /** @var array<string, array{chain_id:int,name:string,rpc_env:string,rpc_fallback:string,block_time_seconds:int,required_confirmations:int,explorer:string}> */
    private array $mainnets;

    /** @var array<string, array{chain_id:int,name:string,rpc_env:string,rpc_fallback:string,required_confirmations:int}> */
    private array $testnets;

    /** @var array<string, array<string, array{address:string,decimals:int,label:string}>>  $key === chain key */
    private array $tokensByChain;

    public function __construct(string $projectDir)
    {
        $chains = Yaml::parseFile($projectDir . '/config/chains.yaml');
        $tokens = Yaml::parseFile($projectDir . '/config/tokens.yaml');

        $this->mainnets      = $chains['chains']  ?? [];
        $this->testnets      = $chains['testnets'] ?? [];
        $this->tokensByChain = $tokens['tokens']   ?? [];
    }

    public function getMainnets(): array { return $this->mainnets; }
    public function getTestnets(): array { return $this->testnets; }

    public function getChainByKey(string $key): ?array
    {
        return $this->mainnets[$key] ?? $this->testnets[$key] ?? null;
    }

    public function getChainById(int $chainId): ?array
    {
        foreach ($this->mainnets as $key => $cfg) {
            if (($cfg['chain_id'] ?? null) === $chainId) {
                return $cfg + ['key' => $key];
            }
        }
        foreach ($this->testnets as $key => $cfg) {
            if (($cfg['chain_id'] ?? null) === $chainId) {
                return $cfg + ['key' => $key];
            }
        }
        return null;
    }

    public function getTokensForChain(string $chainKey): array
    {
        $tokens = $this->tokensByChain[$chainKey] ?? [];
        // Strip the chain_id metadata and return only token-symbol => detail pairs
        unset($tokens['chain_id']);
        return $tokens;
    }

    /**
     * Returns ['chain_id' => int, 'token' => string, 'address' => string, 'decimals' => int]
     * if the (chain, token) pair is allowlisted, null otherwise.
     */
    public function findTokenAddress(string $chainKey, string $symbol): ?array
    {
        $tokens = $this->getTokensForChain($chainKey);
        if (!isset($tokens[$symbol])) {
            return null;
        }
        return [
            'chain_id' => $this->tokensByChain[$chainKey]['chain_id'] ?? 0,
            'token'    => $symbol,
            'address'  => strtolower($tokens[$symbol]['address']),
            'decimals' => (int) $tokens[$symbol]['decimals'],
            'label'    => $tokens[$symbol]['label'] ?? $symbol,
        ];
    }
}
