<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Service\Billing\BillingIntentFactory;
use App\Service\Billing\PlatformWallet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Crypto-native billing (no Stripe — see README §Billing).
 *
 *   GET  /app/billing                     — status page
 *   POST /app/billing/upgrade/{kind}      — kind ∈ {monthly, lifetime} → create intent → redirect to /billing/pay/{uuid}
 *   POST /app/billing/pay-fees            — settle accumulated 1% / 0.5% per-invoice fees
 *   POST /app/billing/cancel              — mark plan_canceled_at, access continues until plan_renews_at
 */
#[IsGranted('ROLE_USER')]
#[Route('/{_locale}/app/billing', requirements: ['_locale' => 'en|uk|es'])]
class BillingController extends AbstractController
{
    public function __construct(
        private readonly BillingIntentFactory $intents,
        private readonly PlatformWallet $platformWallet,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'dashboard_billing', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('dashboard/billing/index.html.twig', [
            'user'             => $user,
            'platform_enabled' => $this->platformWallet->isEnabled(),
            'pro_monthly_usd'  => BillingIntentFactory::PRO_MONTHLY_CENTS / 100,
            'pro_lifetime_usd' => BillingIntentFactory::PRO_LIFETIME_CENTS / 100,
        ]);
    }

    #[Route('/upgrade/{kind}', name: 'dashboard_billing_upgrade',
        requirements: ['kind' => 'monthly|lifetime'],
        methods: ['POST'])]
    public function upgrade(string $kind, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('billing-upgrade', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_billing', ['_locale' => $request->getLocale()]);
        }
        /** @var User $user */
        $user = $this->getUser();

        $intent = $kind === 'lifetime'
            ? $this->intents->createProLifetime($user)
            : $this->intents->createProMonthly($user);

        return $this->redirectToRoute('public_billing_checkout', [
            '_locale' => $request->getLocale(),
            'uuid'    => $intent->getUuid(),
        ]);
    }

    #[Route('/pay-fees', name: 'dashboard_billing_pay_fees', methods: ['POST'])]
    public function payFees(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('billing-pay-fees', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_billing', ['_locale' => $request->getLocale()]);
        }
        /** @var User $user */
        $user = $this->getUser();

        $intent = $this->intents->createFeeSettlement($user);
        if (!$intent) {
            $this->addFlash('info', 'billing.flash.no_fees_owed');
            return $this->redirectToRoute('dashboard_billing', ['_locale' => $request->getLocale()]);
        }

        return $this->redirectToRoute('public_billing_checkout', [
            '_locale' => $request->getLocale(),
            'uuid'    => $intent->getUuid(),
        ]);
    }

    #[Route('/cancel', name: 'dashboard_billing_cancel', methods: ['POST'])]
    public function cancel(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('billing-cancel', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_billing', ['_locale' => $request->getLocale()]);
        }
        /** @var User $user */
        $user = $this->getUser();

        // Soft cancel: keeps Pro until plan_renews_at, then drops to free.
        if ($user->getPlan() === 'pro' && $user->getPlanRenewsAt() !== null) {
            $user->setPlanCanceledAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', $this->translator->trans('billing.flash.canceled', [
                '%date%' => $user->getPlanRenewsAt()->format('Y-m-d'),
            ]));
        }
        return $this->redirectToRoute('dashboard_billing', ['_locale' => $request->getLocale()]);
    }
}
