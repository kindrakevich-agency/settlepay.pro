<?php

namespace App\Controller\Workspace;

use App\Entity\User;
use App\Repository\WorkspaceInvitationRepository;
use App\Service\Workspace\InvitationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public invitation landing.
 *
 *   GET  /{_locale}/workspaces/accept/{token}
 *     - If not logged in: show "Sign in / Create an account to accept"
 *       with the invitation summary. We stash the token in the session
 *       so post-login can route back here.
 *     - If logged in: show a confirm screen + POST to accept.
 *
 *   POST /{_locale}/workspaces/accept/{token}
 *     - Authenticated. Accepts the invite + redirects to dashboard.
 */
#[Route('/{_locale}/workspaces/accept/{token}', requirements: ['_locale' => 'en|uk|es', 'token' => '[a-f0-9]{48}'], defaults: ['_locale' => 'en'])]
class InvitationAcceptController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceInvitationRepository $invitations,
        private readonly InvitationManager $manager,
    ) {}

    #[Route('', name: 'workspaces_invitation_accept', methods: ['GET', 'POST'])]
    public function landing(string $token, Request $request): Response
    {
        $inv = $this->invitations->findByToken($token);
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$inv) {
            return $this->render('workspaces/invitation_invalid.html.twig', [
                'reason' => 'unknown',
            ], new Response('', 404));
        }
        if (!$inv->isPending()) {
            return $this->render('workspaces/invitation_invalid.html.twig', [
                'reason' => $inv->getAcceptedAt() ? 'already_accepted' : 'expired_or_revoked',
                'invitation' => $inv,
            ], new Response('', 410));
        }

        // POST = the accept action. Requires auth + CSRF.
        if ($request->isMethod('POST')) {
            if (!$user) {
                $request->getSession()->set('post_login_redirect', $request->getRequestUri());
                return $this->redirectToRoute('auth_login', ['_locale' => $request->getLocale()]);
            }
            if (!$this->isCsrfTokenValid('invite-accept', (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'errors.csrf_invalid');
                return $this->redirectToRoute('workspaces_invitation_accept', ['_locale' => $request->getLocale(), 'token' => $token]);
            }
            try {
                $this->manager->accept($inv, $user);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->redirectToRoute('workspaces_invitation_accept', ['_locale' => $request->getLocale(), 'token' => $token]);
            }
            $this->addFlash('success', 'invitations.flash.accepted');
            return $this->redirectToRoute('dashboard_home', ['_locale' => $request->getLocale()]);
        }

        // GET — show the appropriate landing page.
        return $this->render('workspaces/invitation_accept.html.twig', [
            'invitation'    => $inv,
            'workspace'     => $inv->getWorkspace(),
            'logged_in'     => $user !== null,
            'email_matches' => $user && strtolower($user->getEmail()) === $inv->getEmail(),
        ]);
    }
}
