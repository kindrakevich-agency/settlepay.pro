<?php

namespace App\Controller\PublicArea;

use App\Enum\BillingIntentStatus;
use App\Repository\BillingIntentRepository;
use App\Service\Blockchain\ChainRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public billing payment flow — `/billing/pay/{uuid}`.
 *
 * Manual-transfer UX (v1):
 *  - Page renders the platform wallet address, the amount, and the
 *    accepted chains/tokens.
 *  - User sends USDC from their wallet of choice (MetaMask, Coinbase,
 *    Binance, anything).
 *  - Page polls /api/v1/public/billing/{uuid} every 5s for status flip.
 *  - On status=paid → message + Continue → /app/billing.
 *
 * Wallet-connect on this page is a Phase-2 polish (the invoice
 * checkout has it; billing can reuse the same JS later).
 */
class BillingCheckoutController extends AbstractController
{
    public function __construct(
        private readonly BillingIntentRepository $intents,
        private readonly ChainRegistry $chains,
    ) {}

    #[Route(
        path: '/{_locale}/billing/pay/{uuid}',
        name: 'public_billing_checkout',
        requirements: [
            '_locale' => 'en|uk|es',
            'uuid'    => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
        ],
        methods: ['GET'],
    )]
    public function show(string $uuid): Response
    {
        $intent = $this->intents->findByUuid($uuid);
        if (!$intent) {
            throw $this->createNotFoundException('Billing intent not found.');
        }

        // Build a list of accepted chains × tokens so the template can
        // show the user where they can send.
        $availableChains = [];
        foreach ($intent->getAcceptedChains() as $chainId) {
            $cfg = $this->chains->getChainById($chainId);
            if (!$cfg) continue;
            $tokens = [];
            foreach ($intent->getAcceptedTokens() as $symbol) {
                $hit = $this->chains->findTokenAddress($cfg['key'], $symbol);
                if ($hit !== null) {
                    $tokens[$symbol] = $hit;
                }
            }
            if ($tokens) {
                $availableChains[] = $cfg + ['tokens' => $tokens];
            }
        }

        return $this->render('billing/checkout.html.twig', [
            'intent'           => $intent,
            'available_chains' => $availableChains,
            'amount_decimal'   => number_format($intent->getAmountCents() / 100, 2, '.', ''),
        ]);
    }

    /** Lightweight JSON used by the page poll. */
    #[Route(
        path: '/api/v1/public/billing/{uuid}',
        name: 'api_public_billing_show',
        requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['GET'],
    )]
    public function apiShow(string $uuid): JsonResponse
    {
        $intent = $this->intents->findByUuid($uuid);
        if (!$intent) {
            return new JsonResponse(['error' => ['code' => 'billing_intent.not_found']], 404);
        }
        return new JsonResponse(['data' => [
            'uuid'         => $intent->getUuid(),
            'status'       => $intent->getStatus()->value,
            'amount_cents' => $intent->getAmountCents(),
            'paid_at'      => $intent->getPaidAt()?->format(\DATE_ATOM),
            'expires_at'   => $intent->getExpiresAt()->format(\DATE_ATOM),
        ]]);
    }

    /** Optional fast-path: page reports the on-chain tx hash before listener catches it. */
    #[Route(
        path: '/api/v1/public/billing/{uuid}/tx',
        name: 'api_public_billing_report_tx',
        requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['POST'],
    )]
    public function reportTx(string $uuid, Request $request): JsonResponse
    {
        $intent = $this->intents->findByUuid($uuid);
        if (!$intent || $intent->getStatus() !== BillingIntentStatus::Pending) {
            return new JsonResponse(['error' => ['code' => 'billing_intent.invalid']], 404);
        }
        $payload = json_decode($request->getContent(), true) ?: [];
        $txHash  = (string) ($payload['tx_hash'] ?? '');
        $chainId = (int) ($payload['chain_id'] ?? 0);
        if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash) || $chainId <= 0) {
            return new JsonResponse(['error' => ['code' => 'billing_intent.invalid_tx']], 400);
        }
        $intent->setClaimedTxHash($txHash)
            ->setClaimedChainId($chainId)
            ->setClaimedAt(new \DateTimeImmutable())
            ->touch();
        $this->intents->save($intent);
        return new JsonResponse(['data' => ['ok' => true]]);
    }
}
