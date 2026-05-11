<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Service\Auth\AddressValidator;
use App\Service\Blockchain\ChainRegistry;
use App\Service\Workspace\WorkspaceContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Phase 2:
 *   - "Profile" splits in two:
 *       • User-level fields (display_name, default_locale, password) live on the User.
 *       • Business-level fields (business_name, business_address, tax_id,
 *         default_currency) live on the active Workspace — only the Owner
 *         can edit them. Members see them read-only.
 *   - Payout wallet is workspace-level, Owner-only.
 */
#[IsGranted('ROLE_USER')]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AddressValidator $addressValidator,
        private readonly ChainRegistry $chains,
        private readonly WorkspaceContext $context,
    ) {}

    #[Route(
        path: '/{_locale}/app/settings',
        name: 'dashboard_settings',
        requirements: ['_locale' => 'en|uk|es'],
        defaults: ['_locale' => 'en'],
        methods: ['GET'],
    )]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);

        return $this->render('dashboard/settings.html.twig', [
            'user'      => $user,
            'workspace' => $workspace,
            'is_owner'  => $this->context->isOwner($user, $workspace),
            'chains'    => $this->chains->getMainnets() + $this->chains->getTestnets(),
        ]);
    }

    #[Route(
        path: '/{_locale}/app/settings/profile',
        name: 'dashboard_settings_profile',
        requirements: ['_locale' => 'en|uk|es'],
        defaults: ['_locale' => 'en'],
        methods: ['POST'],
    )]
    public function updateProfile(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);

        if (!$this->isCsrfTokenValid('settings-profile', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_settings', ['_locale' => $request->getLocale()]);
        }

        // User-level — every member can edit their own display name + locale.
        $user
            ->setDisplayName($this->trimOrNull($request->request->get('display_name')))
            ->setDefaultLocale((string) $request->request->get('default_locale', 'en'))
            ->touch();

        // Workspace-level — Owner only.
        if ($this->context->isOwner($user, $workspace)) {
            $workspace
                ->setBusinessName($this->trimOrNull($request->request->get('business_name')))
                ->setBusinessAddress($this->trimOrNull($request->request->get('business_address')))
                ->setTaxId($this->trimOrNull($request->request->get('tax_id')))
                ->setDefaultCurrency(strtoupper((string) $request->request->get('default_currency', 'USD')))
                ->touch();
        }

        $this->em->flush();
        $this->addFlash('success', 'settings.profile_saved');
        return $this->redirectToRoute('dashboard_settings', ['_locale' => $request->getLocale()]);
    }

    #[Route(
        path: '/{_locale}/app/settings/payout',
        name: 'dashboard_settings_payout',
        requirements: ['_locale' => 'en|uk|es'],
        defaults: ['_locale' => 'en'],
        methods: ['POST'],
    )]
    public function updatePayout(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);

        if (!$this->isCsrfTokenValid('settings-payout', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_settings', ['_locale' => $request->getLocale()]);
        }
        if (!$this->context->isOwner($user, $workspace)) {
            $this->addFlash('error', 'settings.payout_owner_only');
            return $this->redirectToRoute('dashboard_settings', ['_locale' => $request->getLocale()]);
        }

        $rawAddr = (string) $request->request->get('payout_address', '');
        try {
            $address = $this->addressValidator->normalize($rawAddr);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('dashboard_settings', ['_locale' => $request->getLocale()]);
        }

        $chainId = (int) $request->request->get('payout_chain_id', 0);
        $token   = strtoupper((string) $request->request->get('payout_token', 'USDC'));

        $chainCfg = $this->chains->getChainById($chainId);
        if (!$chainCfg) {
            $this->addFlash('error', 'errors.invalid_chain');
            return $this->redirectToRoute('dashboard_settings', ['_locale' => $request->getLocale()]);
        }
        if (!in_array($token, ['USDC', 'USDT', 'DAI'], true)) {
            $this->addFlash('error', 'errors.invalid_token');
            return $this->redirectToRoute('dashboard_settings', ['_locale' => $request->getLocale()]);
        }

        $workspace
            ->setPayoutAddress($address)
            ->setPayoutChainId($chainId)
            ->setPayoutToken($token)
            ->touch();

        $this->em->flush();
        $this->addFlash('success', 'settings.payout_saved');
        return $this->redirectToRoute('dashboard_settings', ['_locale' => $request->getLocale()]);
    }

    #[Route(
        path: '/{_locale}/app/settings/password',
        name: 'dashboard_settings_password',
        requirements: ['_locale' => 'en|uk|es'],
        defaults: ['_locale' => 'en'],
        methods: ['POST'],
    )]
    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('settings-password', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_settings', ['_locale' => $request->getLocale()]);
        }

        $current = (string) $request->request->get('current_password', '');
        $next    = (string) $request->request->get('new_password', '');
        $confirm = (string) $request->request->get('new_password_confirm', '');

        if (!$this->hasher->isPasswordValid($user, $current)) {
            $this->addFlash('error', 'errors.current_password_wrong');
            return $this->redirectToRoute('dashboard_settings', ['_locale' => $request->getLocale()]);
        }
        if (strlen($next) < 12) {
            $this->addFlash('error', 'errors.password_too_short');
            return $this->redirectToRoute('dashboard_settings', ['_locale' => $request->getLocale()]);
        }
        if ($next !== $confirm) {
            $this->addFlash('error', 'errors.password_mismatch');
            return $this->redirectToRoute('dashboard_settings', ['_locale' => $request->getLocale()]);
        }

        $user->setPasswordHash($this->hasher->hashPassword($user, $next))->touch();
        $this->em->flush();

        $this->addFlash('success', 'settings.password_saved');
        return $this->redirectToRoute('dashboard_settings', ['_locale' => $request->getLocale()]);
    }

    private function trimOrNull(?string $v): ?string
    {
        if ($v === null) return null;
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
