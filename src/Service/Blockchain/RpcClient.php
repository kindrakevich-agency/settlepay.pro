<?php

namespace App\Service\Blockchain;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal JSON-RPC 2.0 client for EVM chains.
 *
 * Only exposes the methods we use. Each call accepts the RPC URL so we can
 * keep this stateless and have the BlockListener iterate per chain.
 */
final class RpcClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    /** Returns the current head block number as a base-10 integer. */
    public function blockNumber(string $rpcUrl): int
    {
        $hex = (string) $this->call($rpcUrl, 'eth_blockNumber', []);
        return (int) hexdec(ltrim($hex, '0x'));
    }

    /**
     * eth_getLogs with a filter:
     *   - fromBlock / toBlock         (decimal ints, will be hexed)
     *   - address (string|string[])   (lower-case 0x... contract address)
     *   - topics  (array)             (per JSON-RPC spec; null = wildcard)
     *
     * Returns the raw array of log objects from the node.
     *
     * @return list<array{address:string,topics:list<string>,data:string,blockNumber:string,transactionHash:string,logIndex:string}>
     */
    public function getLogs(string $rpcUrl, int $fromBlock, int $toBlock, string|array $address, array $topics): array
    {
        $filter = [
            'fromBlock' => '0x' . dechex($fromBlock),
            'toBlock'   => '0x' . dechex($toBlock),
            'address'   => $address,
            'topics'    => $topics,
        ];
        $logs = $this->call($rpcUrl, 'eth_getLogs', [$filter]);
        return is_array($logs) ? $logs : [];
    }

    /**
     * eth_getBlockByNumber returning only the header (no full tx list).
     * We only need the timestamp for stamping payments.
     */
    public function blockTimestamp(string $rpcUrl, int $blockNumber): \DateTimeImmutable
    {
        $block = $this->call($rpcUrl, 'eth_getBlockByNumber', ['0x' . dechex($blockNumber), false]);
        if (!is_array($block) || !isset($block['timestamp'])) {
            return new \DateTimeImmutable();
        }
        $unix = (int) hexdec(ltrim((string) $block['timestamp'], '0x'));
        return (new \DateTimeImmutable('@' . $unix))->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Pad a 20-byte EVM address to a 32-byte topic for eth_getLogs filtering.
     *   0x742d35cc6634c0532925a3b844bc9e7595f8bf2c
     * → 0x000000000000000000000000742d35cc6634c0532925a3b844bc9e7595f8bf2c
     */
    public static function addressToTopic(string $address): string
    {
        $clean = strtolower(ltrim($address, '0x'));
        return '0x' . str_pad($clean, 64, '0', STR_PAD_LEFT);
    }

    /**
     * Inverse of addressToTopic — pull the address out of a 32-byte topic.
     */
    public static function topicToAddress(string $topic): string
    {
        return '0x' . substr(strtolower(ltrim($topic, '0x')), -40);
    }

    private function call(string $rpcUrl, string $method, array $params): mixed
    {
        try {
            $response = $this->httpClient->request('POST', $rpcUrl, [
                'json'    => ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
                'timeout' => 20,
            ]);
            $payload = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('RPC call failed', [
                'rpc' => $rpcUrl, 'method' => $method, 'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("RPC {$method} failed: " . $e->getMessage(), previous: $e);
        }

        if (isset($payload['error'])) {
            $msg = is_array($payload['error']) ? ($payload['error']['message'] ?? 'unknown') : (string) $payload['error'];
            throw new \RuntimeException("RPC {$method} error: {$msg}");
        }
        return $payload['result'] ?? null;
    }
}
