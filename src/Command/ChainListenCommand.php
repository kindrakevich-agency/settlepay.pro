<?php

namespace App\Command;

use App\Service\Blockchain\BlockListener;
use App\Service\Blockchain\ChainRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The on-chain listener daemon.
 *
 *   bin/console app:chain:listen                    # all mainnets
 *   bin/console app:chain:listen --testnet          # all testnets only
 *   bin/console app:chain:listen --chain=base       # one specific chain
 *   bin/console app:chain:listen --once             # one tick, then exit
 *                                                     (useful for cron / debugging)
 *
 * Run as a long-running systemd unit in production. See CLAUDE.md §15
 * for the unit file template.
 */
#[AsCommand(
    name: 'app:chain:listen',
    description: 'Poll EVM chains for ERC-20 Transfer events and match them to invoices.',
)]
class ChainListenCommand extends Command
{
    /** Chain block-time → polling cadence (rounded for safety). */
    private const SLEEP_SECONDS = [
        'base'             => 5,
        'polygon'          => 8,
        'arbitrum'         => 5,
        'optimism'         => 5,
        'base_sepolia'     => 5,
        'optimism_sepolia' => 5,
        'arbitrum_sepolia' => 5,
    ];

    public function __construct(
        private readonly BlockListener $listener,
        private readonly ChainRegistry $chains,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('testnet', null, InputOption::VALUE_NONE,     'Listen on testnets instead of mainnets')
            ->addOption('chain',   null, InputOption::VALUE_REQUIRED, 'Listen to one chain only (base, polygon, arbitrum, optimism, base_sepolia, …)')
            ->addOption('once',    null, InputOption::VALUE_NONE,     'Run a single tick per chain and exit')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after N seconds (graceful)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Pick which chains to listen to.
        $chainsToWatch = $this->resolveChainList($input);
        if (empty($chainsToWatch)) {
            $io->error('No chains to watch. Use --chain=<key> or run without --testnet for mainnets.');
            return Command::FAILURE;
        }
        $io->writeln(sprintf('<info>Listening on:</info> %s', implode(', ', $chainsToWatch)));

        $deadline = (int) $input->getOption('time-limit') > 0
            ? time() + (int) $input->getOption('time-limit')
            : PHP_INT_MAX;

        // Graceful shutdown on SIGTERM / SIGINT.
        $shouldStop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $handle = function (int $sig) use (&$shouldStop, $io): void {
                $io->writeln("\n<comment>Received signal {$sig} — finishing current iteration and exiting.</comment>");
                $shouldStop = true;
            };
            pcntl_signal(SIGTERM, $handle);
            pcntl_signal(SIGINT,  $handle);
        }

        $once = (bool) $input->getOption('once');

        while (!$shouldStop && time() < $deadline) {
            foreach ($chainsToWatch as $chainKey) {
                if ($shouldStop) break;
                try {
                    $matched = $this->listener->tick($chainKey);
                    if ($matched > 0) {
                        $io->writeln(sprintf('<info>[%s]</info> matched %d new payment(s)', $chainKey, $matched));
                    }
                } catch (\Throwable $e) {
                    $io->writeln(sprintf('<error>[%s] tick failed: %s</error>', $chainKey, $e->getMessage()));
                    $this->logger->error('Listener tick failed', [
                        'chain' => $chainKey,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Continue on the next chain — don't let one bad chain knock out the others.
                }
            }
            if ($once) break;

            // Sleep for the shortest cadence among the chains we're watching.
            $sleep = min(array_map(static fn(string $k): int => self::SLEEP_SECONDS[$k] ?? 10, $chainsToWatch));
            for ($i = 0; $i < $sleep && !$shouldStop && time() < $deadline; $i++) {
                sleep(1);
            }
        }

        $io->writeln('<comment>Listener exited.</comment>');
        return Command::SUCCESS;
    }

    /** @return list<string> chain keys */
    private function resolveChainList(InputInterface $input): array
    {
        $chain = (string) ($input->getOption('chain') ?? '');
        if ($chain !== '') {
            return $this->chains->getChainByKey($chain) !== null ? [$chain] : [];
        }
        return $input->getOption('testnet')
            ? array_keys($this->chains->getTestnets())
            : array_keys($this->chains->getMainnets());
    }
}
