<?php

namespace App\Controller\Api;

use App\Service\Blockchain\ChainRegistry;
use App\Service\Blockchain\RpcClient;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public health probe at GET /api/v1/health.
 *
 * Returns 200 with the breakdown when every dependency answers, otherwise
 * 503 with the failing components marked `error`. Suitable for uptime
 * monitors — answers in <250ms in healthy state.
 *
 * Components probed:
 *   - db    : Doctrine connection (`SELECT 1`)
 *   - cache : Symfony cache (writes a key, reads it back)
 *   - rpc   : every mainnet RPC, `eth_blockNumber` cached 60s to keep
 *             this cheap on Alchemy's free tier
 */
class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly CacheInterface $cache,
        private readonly ChainRegistry $chains,
        private readonly RpcClient $rpc,
    ) {}

    #[Route('/api/v1/health', name: 'api_health', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $components = [
            'db'    => $this->checkDb(),
            'cache' => $this->checkCache(),
            'rpc'   => $this->checkRpc(),
        ];

        $ok = !array_filter($components, fn ($c) => ($c['status'] ?? 'error') !== 'ok');

        return new JsonResponse([
            'data' => [
                'status'     => $ok ? 'ok' : 'degraded',
                'components' => $components,
                'time'       => (new \DateTimeImmutable())->format(DATE_RFC3339),
            ],
        ], $ok ? 200 : 503);
    }

    private function checkDb(): array
    {
        try {
            $start = microtime(true);
            $this->db->executeQuery('SELECT 1');
            return ['status' => 'ok', 'latency_ms' => (int) ((microtime(true) - $start) * 1000)];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => 'connection failed'];
        }
    }

    private function checkCache(): array
    {
        try {
            $start = microtime(true);
            $value = $this->cache->get('health.probe', function (ItemInterface $item) {
                $item->expiresAfter(5);
                return 'ok';
            });
            return ['status' => $value === 'ok' ? 'ok' : 'error', 'latency_ms' => (int) ((microtime(true) - $start) * 1000)];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => 'cache unavailable'];
        }
    }

    private function checkRpc(): array
    {
        $perChain = [];
        $worstStatus = 'ok';

        foreach ($this->chains->getMainnets() as $key => $cfg) {
            try {
                $head = $this->cache->get('health.rpc.' . $key, function (ItemInterface $item) use ($cfg) {
                    $item->expiresAfter(60);
                    $rpcUrl = $_ENV[$cfg['rpc_env']] ?? $cfg['rpc_fallback'] ?? '';
                    if ($rpcUrl === '') {
                        throw new \RuntimeException('rpc not configured');
                    }
                    return $this->rpc->blockNumber($rpcUrl);
                });
                $perChain[$key] = ['status' => 'ok', 'head_block' => $head];
            } catch (\Throwable $e) {
                $perChain[$key] = ['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 100)];
                $worstStatus = 'error';
            }
        }
        return ['status' => $worstStatus, 'chains' => $perChain];
    }
}
