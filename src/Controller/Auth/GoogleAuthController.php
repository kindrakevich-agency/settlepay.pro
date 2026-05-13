<?php

namespace App\Controller\Auth;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Repository\UserRepository;
use App\Repository\WorkspaceInvitationRepository;
use App\Service\Admin\AdminNotifier;
use App\Service\Auth\GoogleTokenVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Google Identity Services (GIS) sign-in / sign-up.
 *
 * Flow:
 *   1. Browser renders Google's button (rendered by the GIS JS library)
 *   2. User clicks → Google returns a signed ID token (JWT)
 *   3. Browser POSTs the credential to /auth/google
 *   4. We verify the JWT against Google's JWKS, then either log in an
 *      existing user (matched by google_sub OR email) or create a new
 *      one. New users get a workspace + auto-accept of any pending
 *      invitations matching their email.
 *
 * Toggle the whole feature off without redeploying by setting
 * GOOGLE_AUTH_ENABLED=0 in .env.local. The endpoint returns 404 when
 * disabled and the front-end button is hidden.
 */
class GoogleAuthController extends AbstractController
{
    public function __construct(
        private readonly GoogleTokenVerifier $verifier,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly WorkspaceInvitationRepository $invitations,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
        private readonly AdminNotifier $adminNotifier,
        private readonly bool $googleAuthEnabled,
    ) {}

    #[Route('/auth/google', name: 'auth_google', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        if (!$this->googleAuthEnabled) {
            return new JsonResponse(['error' => ['code' => 'auth.google_disabled']], 404);
        }

        $idToken = (string) $request->request->get('credential', '');
        if ($idToken === '') {
            return new JsonResponse(['error' => ['code' => 'auth.missing_credential']], 400);
        }

        // CSRF: Google's GIS docs recommend a double-submit cookie pattern
        // (g_csrf_token in body + cookie). Validate them here.
        $csrfBody   = (string) $request->request->get('g_csrf_token', '');
        $csrfCookie = (string) $request->cookies->get('g_csrf_token', '');
        if ($csrfBody === '' || $csrfCookie === '' || !hash_equals($csrfCookie, $csrfBody)) {
            return new JsonResponse(['error' => ['code' => 'auth.csrf_mismatch']], 400);
        }

        try {
            $claims = $this->verifier->verify($idToken);
        } catch (\DomainException $e) {
            $this->logger->warning('Google ID token verification failed', ['reason' => $e->getMessage()]);
            return new JsonResponse(['error' => ['code' => 'auth.google_invalid', 'message' => $e->getMessage()]], 401);
        }

        $email = strtolower((string) $claims['email']);
        $sub   = (string) $claims['sub'];

        $user = $this->users->findOneBy(['googleSub' => $sub])
            ?? $this->users->findOneBy(['email' => $email]);

        $isNewUser = false;
        if (!$user) {
            $user = $this->createUserFromClaims($email, $sub, $claims, $request);
            $this->provisionWorkspace($user);
            $this->autoAcceptInvitations($user);
            $isNewUser = true;
        } else {
            // Auto-link by email: stamp the google_sub on first Google sign-in
            // for a pre-existing email/password user.
            if ($user->getGoogleSub() === null) {
                $user->setGoogleSub($sub);
            }
            // Ensure they have at least one workspace (covers users created
            // before the registration fix in c2a7eed).
            if (count($this->em->getRepository(WorkspaceMember::class)->findBy(['user' => $user])) === 0) {
                $this->provisionWorkspace($user);
                $this->autoAcceptInvitations($user);
            }
            if ($user->getEmailVerifiedAt() === null) {
                $user->setEmailVerifiedAt(new \DateTimeImmutable());
            }
        }
        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->em->flush();

        if ($isNewUser) {
            $this->adminNotifier->notifyNewSignup($user, 'google');
        }

        // Authenticate against the 'main' firewall — same as form_login.
        $this->security->login($user, firewallName: 'main');

        $locale = $request->getLocale() ?: $user->getDefaultLocale();
        $target = $request->request->get('post_login_redirect')
            ?: $request->getSession()->get('post_login_redirect')
            ?: $this->generateUrl('dashboard_home', ['_locale' => $locale]);

        return new RedirectResponse($target);
    }

    private function createUserFromClaims(string $email, string $sub, array $claims, Request $request): User
    {
        $local = strstr($email, '@', true) ?: $email;
        $user = (new User())
            ->setEmail($email)
            ->setGoogleSub($sub)
            ->setDisplayName($claims['name'] ?? $local)
            ->setEmailVerifiedAt(new \DateTimeImmutable())     // Google verified it
            ->setPayoutAddress('0x0000000000000000000000000000000000000000')
            ->setPayoutChainId(8453)
            ->setPayoutToken('USDC')
            ->setDefaultLocale($this->pickLocale($claims, $request));

        // A password is non-nullable on the column, but Google users won't
        // use it. Set a random unguessable value so they can later request a
        // password-reset to enable email/password as a second method.
        $randomPassword = bin2hex(random_bytes(24));
        $user->setPasswordHash($this->hasher->hashPassword($user, $randomPassword));

        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    private function pickLocale(array $claims, Request $request): string
    {
        $locale = $claims['locale'] ?? $request->getLocale() ?? 'en';
        $short  = substr((string) $locale, 0, 2);
        return in_array($short, ['en', 'uk', 'es'], true) ? $short : 'en';
    }

    private function provisionWorkspace(User $user): void
    {
        $local = strstr($user->getEmail(), '@', true) ?: $user->getEmail();
        $workspace = (new Workspace())
            ->setName($local . "'s workspace")
            ->setOwner($user)
            ->setPayoutAddress($user->getPayoutAddress())
            ->setPayoutChainId($user->getPayoutChainId())
            ->setPayoutToken($user->getPayoutToken())
            ->setDefaultLocale($user->getDefaultLocale());
        $member = (new WorkspaceMember())
            ->setWorkspace($workspace)
            ->setUser($user)
            ->setRole(WorkspaceMember::ROLE_OWNER);
        $this->em->persist($workspace);
        $this->em->persist($member);
        $this->em->flush();
    }

    private function autoAcceptInvitations(User $user): void
    {
        foreach ($this->invitations->findPendingByEmail($user->getEmail()) as $inv) {
            $member = (new WorkspaceMember())
                ->setWorkspace($inv->getWorkspace())
                ->setUser($user)
                ->setRole($inv->getRole());
            $inv->markAccepted();
            $this->em->persist($member);
        }
        $this->em->flush();
    }
}
