<?php

namespace App\Controller\Auth;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Repository\UserRepository;
use App\Repository\WorkspaceInvitationRepository;
use App\Service\Auth\AuthMailer;
use App\Service\Auth\TokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TokenGenerator $tokens,
        private readonly AuthMailer $mailer,
        private readonly WorkspaceInvitationRepository $invitations,
    ) {}

    #[Route(
        path: '/{_locale}/register',
        name: 'auth_register',
        requirements: ['_locale' => 'en|uk|es'],
        defaults: ['_locale' => 'en'],
        methods: ['GET', 'POST'],
    )]
    public function register(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard_home');
        }

        $form = ['email' => '', 'errors' => []];

        if ($request->isMethod('POST')) {
            $email    = trim((string) $request->request->get('email', ''));
            $password = (string) $request->request->get('password', '');
            $confirm  = (string) $request->request->get('password_confirm', '');
            $form['email'] = $email;

            if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_csrf_token'))) {
                $form['errors'][] = 'errors.csrf_invalid';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $form['errors'][] = 'errors.invalid_email';
            }
            if (strlen($password) < 12) {
                $form['errors'][] = 'errors.password_too_short';
            }
            if ($password !== $confirm) {
                $form['errors'][] = 'errors.password_mismatch';
            }
            if ($email !== '' && $this->users->findByEmail($email)) {
                // Don't leak which emails are registered — silently accept and
                // resend verification. But we still need to abort the new-user
                // creation path. Mirror behaviour of the password-reset
                // endpoint which always says "we sent the email" regardless.
                if (empty($form['errors'])) {
                    return $this->redirectToRoute('auth_check_email', ['_locale' => $request->getLocale()]);
                }
            }

            if (empty($form['errors'])) {
                $user = (new User())
                    ->setEmail(strtolower($email))
                    ->setDisplayName(strstr($email, '@', true) ?: null)
                    ->setPayoutAddress('0x0000000000000000000000000000000000000000')   // placeholder until /settings
                    ->setPayoutChainId(8453)                                            // default Base mainnet
                    ->setPayoutToken('USDC')
                    ->setDefaultLocale($request->getLocale());
                $user->setPasswordHash($this->hasher->hashPassword($user, $password));

                // Verification token
                $plain = $this->tokens->generatePlain();
                $user->setEmailVerificationToken($this->tokens->hash($plain));
                $user->setEmailVerificationExpiresAt(new \DateTimeImmutable('+24 hours'));

                $this->em->persist($user);
                $this->em->flush();

                $this->provisionWorkspace($user);
                $this->autoAcceptInvitations($user);
                $this->em->flush();

                try {
                    $this->mailer->sendVerificationEmail($user, $plain);
                } catch (\Throwable $e) {
                    // Mail failure shouldn't block account creation — user can
                    // request a resend from /verify-email.
                }

                return $this->redirectToRoute('auth_check_email', ['_locale' => $request->getLocale()]);
            }
        }

        return $this->render('auth/register.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(
        path: '/{_locale}/check-email',
        name: 'auth_check_email',
        requirements: ['_locale' => 'en|uk|es'],
        defaults: ['_locale' => 'en'],
        methods: ['GET'],
    )]
    public function checkEmail(): Response
    {
        return $this->render('auth/check_email.html.twig');
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
    }

    private function autoAcceptInvitations(User $user): void
    {
        foreach ($this->invitations->findPendingByEmail($user->getEmail()) as $inv) {
            $workspace = $inv->getWorkspace();
            $member = (new WorkspaceMember())
                ->setWorkspace($workspace)
                ->setUser($user)
                ->setRole($inv->getRole());
            $inv->markAccepted();
            $this->em->persist($member);
        }
    }

    #[Route(
        path: '/{_locale}/verify-email/{token}',
        name: 'auth_verify_email',
        requirements: ['_locale' => 'en|uk|es', 'token' => '[A-Za-z0-9_-]{20,128}'],
        defaults: ['_locale' => 'en'],
        methods: ['GET'],
    )]
    public function verifyEmail(string $token): Response
    {
        $hash = $this->tokens->hash($token);
        $user = $this->users->findOneBy(['emailVerificationToken' => $hash]);

        $errored = false;
        if (!$user
            || $user->getEmailVerificationExpiresAt() === null
            || $user->getEmailVerificationExpiresAt() < new \DateTimeImmutable()
        ) {
            $errored = true;
        } else {
            $user
                ->setEmailVerifiedAt(new \DateTimeImmutable())
                ->setEmailVerificationToken(null)
                ->setEmailVerificationExpiresAt(null)
                ->touch();
            $this->em->flush();
        }

        return $this->render('auth/verified.html.twig', ['errored' => $errored]);
    }
}
