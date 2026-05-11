<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Repository\InvoiceRepository;
use App\Service\Billing\BillingIntentFactory;
use App\Service\Billing\PlatformWallet;
use App\Service\Workspace\WorkspaceContext;
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
 * Phase 2: billing is workspace-scoped; only the workspace Owner can
 * upgrade / cancel / settle fees.
 */
#[IsGranted('ROLE_USER')]
#[Route('/{_locale}/app/billing', requirements: ['_locale' => 'en|uk|es'])]
class BillingController extends AbstractController
{
    public const FREE_VOLUME_CAP_CENTS = 100000; // $1,000

    public function __construct(
        private readonly BillingIntentFactory $intents,
        private readonly PlatformWallet $platformWallet,
        private readonly InvoiceRepository $invoices,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly WorkspaceContext $context,
    ) {}

    #[Route('', name: 'dashboard_billing', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);

        $mtdCents = $this->invoices->monthToDateIssuedCents($workspace);
        $isOwner  = $this->context->isOwner($user, $workspace);

        return $this->render('dashboard/billing/index.html.twig', [
            'workspace'          => $workspace,
            'is_owner'           => $isOwner,
            'platform_enabled'   => $this->platformWallet->isEnabled(),
            'pro_monthly_usd'    => BillingIntentFactory::PRO_MONTHLY_CENTS / 100,
            'pro_lifetime_usd'   => BillingIntentFactory::PRO_LIFETIME_CENTS / 100,
            'agency_monthly_usd' => BillingIntentFactory::AGENCY_MONTHLY_CENTS / 100,
            'mtd_volume_cents'   => $mtdCents,
            'free_cap_cents'     => self::FREE_VOLUME_CAP_CENTS,
            'cap_remaining_cents'=> max(0, self::FREE_VOLUME_CAP_CENTS - $mtdCents),
        ]);
    }

    #[Route('/upgrade/{kind}', name: 'dashboard_billing_upgrade',
        requirements: ['kind' => 'monthly|lifetime|agency'],
        methods: ['POST'])]
    public function upgrade(string $kind, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('billing-upgrade', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_billing', ['_locale' => $request->getLocale()]);
        }
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);

        if (!$this->context->isOwner($user, $workspace)) {
            $this->addFlash('error', 'billing.owner_only');
            return $this->redirectToRoute('dashboard_billing', ['_locale' => $request->getLocale()]);
        }

        $intent = match ($kind) {
            'lifetime' => $this->intents->createProLifetime($workspace, $user),
            'agency'   => $this->intents->createAgencyMonthly($workspace, $user),
            default    => $this->intents->createProMonthly($workspace, $user),
        };

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
        $workspace = $this->context->current($user);
        if (!$this->context->isOwner($user, $workspace)) {
            $this->addFlash('error', 'billing.owner_only');
            return $this->redirectToRoute('dashboard_billing', ['_locale' => $request->getLocale()]);
        }

        $intent = $this->intents->createFeeSettlement($workspace, $user);
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
        $workspace = $this->context->current($user);
        if (!$this->context->isOwner($user, $workspace)) {
            $this->addFlash('error', 'billing.owner_only');
            return $this->redirectToRoute('dashboard_billing', ['_locale' => $request->getLocale()]);
        }

        if (in_array($workspace->getPlan(), ['pro', 'agency'], true) && $workspace->getPlanRenewsAt() !== null) {
            $workspace->setPlanCanceledAt(new \DateTimeImmutable())->touch();
            $this->em->flush();
            $this->addFlash('success', $this->translator->trans('billing.flash.canceled', [
                '%date%' => $workspace->getPlanRenewsAt()->format('Y-m-d'),
            ]));
        }
        return $this->redirectToRoute('dashboard_billing', ['_locale' => $request->getLocale()]);
    }
}
