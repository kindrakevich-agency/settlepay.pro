<?php

namespace App\Command;

use App\Entity\Invoice;
use App\Entity\InvoiceLineItem;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates a demo user + invoice we can hit via /pay/{uuid}.
 *
 *   bin/console app:invoice:create-sample
 *   bin/console app:invoice:create-sample --testnet  (use Base Sepolia chain id 84532)
 *   bin/console app:invoice:create-sample --recipient=0xabc... --amount-cents=245000
 */
#[AsCommand(
    name: 'app:invoice:create-sample',
    description: 'Seed a sample User + Invoice for testing the public payment page.',
)]
class CreateSampleInvoiceCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email',         null, InputOption::VALUE_REQUIRED, 'Owner email',       'demo@settlepay.pro')
            ->addOption('business',      null, InputOption::VALUE_REQUIRED, 'Business name',     "Vitalii's Studio")
            ->addOption('client',        null, InputOption::VALUE_REQUIRED, 'Client name',       'Acme Corp')
            ->addOption('client-email',  null, InputOption::VALUE_REQUIRED, 'Client email',      'client@acme.example')
            ->addOption('amount-cents',  null, InputOption::VALUE_REQUIRED, 'Amount in cents',   '245000')
            ->addOption('currency',      null, InputOption::VALUE_REQUIRED, 'Currency',          'USD')
            ->addOption('recipient',     null, InputOption::VALUE_REQUIRED, 'Payout wallet',     '0x742d35Cc6634C0532925a3b844Bc9e7595f8Bf2c')
            ->addOption('testnet',       null, InputOption::VALUE_NONE,     'Use Base Sepolia (84532) instead of mainnets');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email     = (string) $input->getOption('email');
        $business  = (string) $input->getOption('business');
        $client    = (string) $input->getOption('client');
        $cEmail    = (string) $input->getOption('client-email');
        $amount    = (int) $input->getOption('amount-cents');
        $currency  = strtoupper((string) $input->getOption('currency'));
        $recipient = (string) $input->getOption('recipient');
        $testnet   = (bool) $input->getOption('testnet');

        // Find or create the owner user
        $user = $this->users->findByEmail($email);
        if (!$user) {
            $user = new User();
            $user
                ->setEmail($email)
                ->setBusinessName($business)
                ->setDisplayName($business)
                ->setPayoutAddress($recipient)
                ->setPayoutChainId($testnet ? 84532 : 8453)
                ->setPayoutToken('USDC')
                ->setDefaultCurrency($currency)
                ->setDefaultLocale('en');
            $user->setPasswordHash($this->hasher->hashPassword($user, bin2hex(random_bytes(8))));
            $this->em->persist($user);
            $io->writeln("<info>Created user</info> {$email}");
        } else {
            $io->writeln("<comment>Reusing existing user</comment> {$email}");
        }

        // Build the invoice
        $invoice = new Invoice();
        $invoice
            ->setUser($user)
            ->setNumber(sprintf('INV-%s-%04d', date('Y'), random_int(1000, 9999)))
            ->setStatus(InvoiceStatus::Sent)
            ->setAmountCents($amount)
            ->setCurrency($currency)
            ->setClientName($client)
            ->setClientEmail($cEmail)
            ->setDescription('Brand identity & logo iterations')
            ->setIssuedAt(new \DateTimeImmutable())
            ->setDueDate((new \DateTimeImmutable('+14 days')))
            ->setRecipientAddress($recipient)
            ->setAcceptedChains($testnet ? [84532] : [8453, 137, 42161, 10])
            ->setAcceptedTokens(['USDC']);

        $invoice->addLineItem(
            (new InvoiceLineItem())
                ->setDescription('Brand identity (10h)')
                ->setQuantity('10.00')
                ->setUnitPriceCents(180_00)
                ->setTotalCents(1800_00)
                ->setPosition(0)
        );
        $invoice->addLineItem(
            (new InvoiceLineItem())
                ->setDescription('Logo iterations (4h)')
                ->setQuantity('4.00')
                ->setUnitPriceCents(162_50)
                ->setTotalCents(650_00)
                ->setPosition(1)
        );

        $this->em->persist($invoice);
        $this->em->flush();

        $url = sprintf('https://settlepay.pro/en/pay/%s', $invoice->getUuid());

        $io->success("Sample invoice created.\n  number   {$invoice->getNumber()}\n  amount   \${$amount} cents\n  client   {$client}\n  network  " . ($testnet ? 'Base Sepolia (testnet)' : 'Base + Polygon + Arbitrum + Optimism'));
        $io->writeln("Public payment URL:\n  {$url}");

        return Command::SUCCESS;
    }
}
