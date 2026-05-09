<?php

namespace App\Service\Blockchain;

use App\Repository\ChainCursorRepository;
use App\Repository\InvoiceRepository;
use App\Service\Payment\PaymentMatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Polls one EVM chain for ERC-20 Transfer events to our recipient
 * addresses and dispatches them to PaymentMatcher.
 *
 * Per CLAUDE.md §9 — this is THE listener. The whole system depends
 * on it being correct, idempotent, and resilient to RPC flakes.
 */
final class BlockListener
{
    /** Cap each eth_getLogs call so RPC providers don't reject the range. */
    private const MAX_BATCH_BLOCKS = 500;

    public function __construct(
        private readonly RpcClient $rpcClient,
        private readonly EventDecoder $decoder,
        private readonly ChainRegistry $chains,
        private readonly ChainCursorRepository $cursors,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentMatcher $matcher,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::APP_ENV)%')] private readonly string $appEnv = 'prod',
    ) {}

    /**
     * Run a single iteration: process whatever blocks are now confirmed.
     *
     * Returns the number of new logs successfully processed (not counting
     * skipped duplicates or orphans).
     *
     * The caller (ChainListenCommand) wraps this in a sleep/repeat loop.
     */
    public function tick(string $chainKey): int
    {
        $chainCfg = $this->chains->getChainByKey($chainKey);
        if (!$chainCfg) {
            throw new \InvalidArgumentException("Unknown chain: {$chainKey}");
        }
        $chainId = (int) $chainCfg['chain_id'];

        // Where are we?
        $cursor = $this->cursors->getOrCreate($chainId, startBlock: 0);
        $rpcUrl = $this->resolveRpcUrl($chainCfg);
        $head   = $this->rpcClient->blockNumber($rpcUrl);
        $needed = (int) ($chainCfg['required_confirmations'] ?? 5);

        // First-ever cursor read: jump to head minus a buffer so we don't
        // re-scan months of history. After that we walk forward.
        if ($cursor->getLastProcessedBlock() === 0) {
            $cursor->setLastProcessedBlock(max(0, $head - $needed - 10));
            $this->cursors->save($cursor);
            $this->logger->info('Bootstrapping cursor', ['chain' => $chainKey, 'start_block' => $cursor->getLastProcessedBlock()]);
        }

        $from = $cursor->getLastProcessedBlock() + 1;
        $to   = min($from + self::MAX_BATCH_BLOCKS - 1, $head - $needed);

        if ($to < $from) {
            // Nothing new is confirmed yet.
            return 0;
        }

        // Build the filter.
        $tokens = $this->chains->getTokensForChain($chainKey);
        if (empty($tokens)) {
            $this->logger->debug('No tokens configured for chain — skipping', ['chain' => $chainKey]);
            $cursor->setLastProcessedBlock($to);
            $this->cursors->save($cursor);
            return 0;
        }
        $contractAddresses = array_values(array_map(static fn(array $t): string => strtolower($t['address']), $tokens));

        $recipients = $this->invoices->getOpenRecipientAddresses();
        if (empty($recipients)) {
            // No open invoices — nothing to listen for. Still advance the
            // cursor so we don't re-scan once invoices appear.
            $cursor->setLastProcessedBlock($to);
            $this->cursors->save($cursor);
            return 0;
        }
        $recipientTopics = array_map(RpcClient::addressToTopic(...), $recipients);

        // topics layout: [signature, from-wildcard, [to-list]]
        $topics = [
            EventDecoder::TRANSFER_SIGNATURE,
            null,
            $recipientTopics,
        ];

        $logs = $this->rpcClient->getLogs($rpcUrl, $from, $to, $contractAddresses, $topics);
        $this->logger->debug('Fetched logs', [
            'chain' => $chainKey, 'from' => $from, 'to' => $to, 'count' => count($logs),
        ]);

        $processed = 0;
        $blockTimestamps = []; // small per-batch cache so repeated logs in the same block don't re-RPC
        foreach ($logs as $log) {
            $decoded = $this->decoder->decodeTransfer($log);
            if ($decoded === null) continue;

            // Resolve which token symbol/decimals this contract is.
            $tokenInfo = null;
            foreach ($tokens as $symbol => $detail) {
                if ($symbol === 'chain_id') continue;
                if (strtolower($detail['address']) === $decoded['token_address']) {
                    $tokenInfo = ['symbol' => $symbol, 'decimals' => (int) $detail['decimals']];
                    break;
                }
            }
            if ($tokenInfo === null) {
                // The contract address came back unrecognized — should never
                // happen because we passed our allowlist as the filter, but
                // defence-in-depth.
                $this->logger->warning('Transfer from non-allowlisted contract — ignored', $decoded);
                continue;
            }

            // Block timestamp for the payment record.
            $blockNumber = $decoded['block_number'];
            if (!isset($blockTimestamps[$blockNumber])) {
                $blockTimestamps[$blockNumber] = $this->rpcClient->blockTimestamp($rpcUrl, $blockNumber);
            }

            $payment = $this->matcher->process(
                chainId:        $chainId,
                transfer:       $decoded,
                token:          $tokenInfo,
                confirmations:  $needed,
                blockTimestamp: $blockTimestamps[$blockNumber],
            );
            if ($payment !== null) {
                $processed++;
            }
        }

        $cursor->setLastProcessedBlock($to);
        $this->cursors->save($cursor);

        if ($processed > 0) {
            $this->logger->info('Block range processed', [
                'chain' => $chainKey, 'from' => $from, 'to' => $to, 'matched' => $processed,
            ]);
        }
        return $processed;
    }

    private function resolveRpcUrl(array $chainCfg): string
    {
        $envName = $chainCfg['rpc_env'] ?? null;
        if ($envName) {
            $val = $_ENV[$envName] ?? $_SERVER[$envName] ?? null;
            if ($val) return $val;
        }
        return (string) ($chainCfg['rpc_fallback'] ?? '');
    }
}
