<?php

namespace App\Controller\Auth;

use App\Repository\UserRepository;
use App\Service\Auth\AuthMailer;
use App\Service\Auth\TokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly TokenGenerator $tokens,
        private readonly AuthMailer $mailer,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RateLimiterFactory $passwordResetRequestLimiter,
    ) {}

    #[Route(
        path: '/{_locale}/forgot-password',
        name: 'auth_forgot_password',
        requirements: ['_locale' => 'en|uk|es'],
        defaults: ['_locale' => 'en'],
        methods: ['GET', 'POST'],
    )]
    public function forgot(Request $request): Response
    {
        $form = ['email' => '', 'errors' => [], 'sent' => false];

        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email', ''));
            $form['email'] = $email;

            if (!$this->isCsrfTokenValid('forgot-password', (string) $request->request->get('_csrf_token'))) {
                $form['errors'][] = 'errors.csrf_invalid';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $form['errors'][] = 'errors.invalid_email';
            } else {
                $limiter = $this->passwordResetRequestLimiter->create($request->getClientIp() ?? 'anon');
                if (!$limiter->consume()->isAccepted()) {
                    throw new TooManyRequestsHttpException(3600);
                }

                $user = $this->users->findByEmail(strtolower($email));
                if ($user) {
                    $plain = $this->tokens->generatePlain();
                    $user
                        ->setPasswordResetToken($this->tokens->hash($plain))
                        ->setPasswordResetExpiresAt(new \DateTimeImmutable('+1 hour'))
                        ->touch();
                    $this->em->flush();

                    try {
                        $this->mailer->sendPasswordResetEmail($user, $plain);
                    } catch (\Throwable $e) {
                        // Mail failure is silent on this endpoint — never leak whether the
                        // email exists. The "sent" UX is shown regardless.
                    }
                }
                // Always show "we sent the email" — never confirm whether the
                // address is registered. CLAUDE.md security checklist.
                $form['sent'] = true;
            }
        }

        return $this->render('auth/forgot_password.html.twig', ['form' => $form]);
    }

    #[Route(
        path: '/{_locale}/reset-password/{token}',
        name: 'auth_reset_password',
        requirements: ['_locale' => 'en|uk|es', 'token' => '[A-Za-z0-9_-]{20,128}'],
        defaults: ['_locale' => 'en'],
        methods: ['GET', 'POST'],
    )]
    public function reset(string $token, Request $request): Response
    {
        $hash = $this->tokens->hash($token);
        $user = $this->users->findOneBy(['passwordResetToken' => $hash]);

        if (!$user
            || $user->getPasswordResetExpiresAt() === null
            || $user->getPasswordResetExpiresAt() < new \DateTimeImmutable()
        ) {
            return $this->render('auth/reset_password.html.twig', ['expired' => true, 'form' => null]);
        }

        $form = ['errors' => []];

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            $confirm  = (string) $request->request->get('password_confirm', '');

            if (!$this->isCsrfTokenValid('reset-password', (string) $request->request->get('_csrf_token'))) {
                $form['errors'][] = 'errors.csrf_invalid';
            }
            if (strlen($password) < 12) {
                $form['errors'][] = 'errors.password_too_short';
            }
            if ($password !== $confirm) {
                $form['errors'][] = 'errors.password_mismatch';
            }

            if (empty($form['errors'])) {
                $user
                    ->setPasswordHash($this->hasher->hashPassword($user, $password))
                    ->setPasswordResetToken(null)
                    ->setPasswordResetExpiresAt(null)
                    ->touch();
                $this->em->flush();

                return $this->render('auth/reset_password.html.twig', ['done' => true, 'form' => null, 'expired' => false]);
            }
        }

        return $this->render('auth/reset_password.html.twig', [
            'form'    => $form,
            'token'   => $token,
            'expired' => false,
        ]);
    }
}
