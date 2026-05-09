<?php

namespace App\Controller\PublicArea;

use App\Repository\InvoiceRepository;
use App\Service\Blockchain\ChainRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public payment checkout — `/pay/{uuid}`.
 *
 * Single-purpose, no auth, no nav. The page is **revenue critical**;
 * any change here must be reviewed against CLAUDE.md §9.
 */
class PaymentCheckoutController extends AbstractController
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly EntityManagerInterface $em,
        private readonly ChainRegistry $chains,
    ) {}

    #[Route(
        path: '/{_locale}/pay/{uuid}',
        name: 'public_payment_checkout',
        requirements: [
            '_locale' => 'en|uk|es',
            'uuid'    => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
        ],
        defaults: ['_locale' => 'en'],
        methods: ['GET'],
    )]
    public function show(string $uuid): Response
    {
        $invoice = $this->invoices->findByUuid($uuid);
        if (!$invoice) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        // Mark the invoice as viewed on first open. Idempotent.
        if ($invoice->markViewed()) {
            $this->em->flush();
        }

        // Build the chain/token allowlist for the front-end so it knows what
        // contracts to call and on which network.
        $availableChains = [];
        foreach ($invoice->getAcceptedChains() as $chainId) {
            $cfg = $this->chains->getChainById($chainId);
            if (!$cfg) continue;

            $tokens = [];
            foreach ($invoice->getAcceptedTokens() as $symbol) {
                $hit = $this->chains->findTokenAddress($cfg['key'], $symbol);
                if ($hit !== null) {
                    $tokens[$symbol] = $hit;
                }
            }
            if ($tokens) {
                $availableChains[] = $cfg + ['tokens' => $tokens];
            }
        }

        return $this->render('payment/checkout.html.twig', [
            'invoice'          => $invoice,
            'available_chains' => $availableChains,
            'amount_decimal'   => number_format($invoice->getAmountCents() / 100, 2, '.', ''),
        ]);
    }
}
