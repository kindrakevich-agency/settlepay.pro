<?php

namespace App\Service\Blockchain;

use App\Repository\ChainCursorRepository;
use App\Repository\InvoiceRepository;
use App\Service\Billing\BillingPaymentMatcher;
use App\Service\Billing\PlatformWallet;
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
    public function __construct(
        private readonly RpcClient $rpcClient,
        private readonly EventDecoder $decoder,
        private readonly ChainRegistry $chains,
        private readonly ChainCursorRepository $cursors,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentMatcher $matcher,
        private readonly BillingPaymentMatcher $billingMatcher,
        private readonly PlatformWallet $platformWallet,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::APP_ENV)%')] private readonly string $appEnv = 'prod',
        /**
         * Cap each eth_getLogs call so RPC providers don't reject the range.
         * Alchemy free tier rejects > 10 blocks; PAYG / publicnode allow
         * thousands. Override via LISTENER_MAX_BATCH_BLOCKS in .env.local.
         */
        #[Autowire('%env(int:LISTENER_MAX_BATCH_BLOCKS)%')]
        private readonly int $maxBatchBlocks = 9,
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

        // First-ever cursor read: jump to head minus a small buffer so we
        // don't re-scan months of history AND don't exceed any RPC range
        // limit on the very first tick. After that we walk forward.
        if ($cursor->getLastProcessedBlock() === 0) {
            $cursor->setLastProcessedBlock(max(0, $head - $needed - 1));
            $this->cursors->save($cursor);
            $this->logger->info('Bootstrapping cursor', ['chain' => $chainKey, 'start_block' => $cursor->getLastProcessedBlock()]);
        }

        $from = $cursor->getLastProcessedBlock() + 1;
        $to   = min($from + $this->maxBatchBlocks - 1, $head - $needed);

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
        // Also watch the platform wallet (billing payments — subscriptions,
        // fee settlements). Same listener, two destinations.
        $platformAddr = $this->platformWallet->getAddress();
        if ($platformAddr !== null) {
            $recipients[] = $platformAddr;
            $recipients = array_values(array_unique($recipients));
        }
        if (empty($recipients)) {
            // No open invoices AND no platform wallet — nothing to listen for.
            // Still advance the cursor so we don't re-scan once anything appears.
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

            // Routing — supports platform_wallet === user.payout_address:
            //
            //   1. If the recipient is the platform wallet, TRY billing first.
            //      BillingPaymentMatcher only matches when the `from` address
            //      matches the intent's expected_payer (set to the user's own
            //      payout wallet at intent-creation time). So self-payments
            //      (Pro renewals, fee settlements) match here.
            //   2. If billing returns null, fall through to invoice flow.
            //      Client payments to the SAME wallet (different `from`) end
            //      up here and match an open invoice as usual.
            //   3. If recipient isn't the platform wallet, only invoice flow.
            //
            // This is what makes dual-purpose wallets work — same address
            // serves both invoice-receive and platform-fee-receive roles.
            $payment = null;
            if ($platformAddr !== null && $decoded['to'] === $platformAddr) {
                $payment = $this->billingMatcher->process(
                    chainId:        $chainId,
                    transfer:       $decoded,
                    token:          $tokenInfo,
                    confirmations:  $needed,
                    blockTimestamp: $blockTimestamps[$blockNumber],
                );
            }
            if ($payment === null) {
                $payment = $this->matcher->process(
                    chainId:        $chainId,
                    transfer:       $decoded,
                    token:          $tokenInfo,
                    confirmations:  $needed,
                    blockTimestamp: $blockTimestamps[$blockNumber],
                );
            }
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
