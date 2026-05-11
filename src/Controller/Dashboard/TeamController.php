<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Repository\WorkspaceInvitationRepository;
use App\Service\Workspace\InvitationManager;
use App\Service\Workspace\WorkspaceContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Workspace membership management.
 *
 *   GET  /app/settings/team                 — list members + pending invites
 *   POST /app/settings/team/invite          — owner-only, send invite
 *   POST /app/settings/team/invite/{id}/revoke   — owner-only
 *   POST /app/settings/team/member/{userId}/remove — owner-only
 *   POST /app/settings/team/leave           — non-owner self-removes
 */
#[IsGranted('ROLE_USER')]
#[Route('/{_locale}/app/settings/team', requirements: ['_locale' => 'en|uk|es'], defaults: ['_locale' => 'en'])]
class TeamController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly InvitationManager $invitations,
        private readonly WorkspaceInvitationRepository $invitationRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'dashboard_team', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);
        $member = $this->context->memberFor($user, $workspace);

        return $this->render('dashboard/settings/team.html.twig', [
            'user'        => $user,
            'workspace'   => $workspace,
            'is_owner'    => $member->isOwner(),
            'members'     => $workspace->getMembers(),
            'invitations' => $this->invitationRepo->findPendingFor($workspace),
        ]);
    }

    #[Route('/invite', name: 'dashboard_team_invite', methods: ['POST'])]
    public function invite(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);
        $this->context->requireOwner($user, $workspace);

        if (!$this->isCsrfTokenValid('team-invite', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_team', ['_locale' => $request->getLocale()]);
        }
        if (!$workspace->isAgency()) {
            $this->addFlash('error', 'team.agency_plan_required');
            return $this->redirectToRoute('dashboard_team', ['_locale' => $request->getLocale()]);
        }

        $email = (string) $request->request->get('email', '');
        try {
            $this->invitations->invite($workspace, $email, $user);
            $this->addFlash('success', 'team.flash.invited');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('dashboard_team', ['_locale' => $request->getLocale()]);
    }

    #[Route('/invite/{id}/revoke', name: 'dashboard_team_invite_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(int $id, Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);
        $this->context->requireOwner($user, $workspace);

        if (!$this->isCsrfTokenValid('team-invite-revoke', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_team', ['_locale' => $request->getLocale()]);
        }
        $inv = $this->invitationRepo->find($id);
        if (!$inv || $inv->getWorkspace()->getId() !== $workspace->getId()) {
            throw new NotFoundHttpException();
        }
        $this->invitations->revoke($inv);
        $this->addFlash('success', 'team.flash.invite_revoked');
        return $this->redirectToRoute('dashboard_team', ['_locale' => $request->getLocale()]);
    }

    #[Route('/member/{userId}/remove', name: 'dashboard_team_member_remove', methods: ['POST'], requirements: ['userId' => '\d+'])]
    public function removeMember(int $userId, Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);
        $this->context->requireOwner($user, $workspace);

        if (!$this->isCsrfTokenValid('team-member-remove', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_team', ['_locale' => $request->getLocale()]);
        }

        foreach ($workspace->getMembers() as $m) {
            if ((int) $m->getUser()->getId() === $userId) {
                if ($m->isOwner()) {
                    $this->addFlash('error', 'team.cannot_remove_owner');
                    return $this->redirectToRoute('dashboard_team', ['_locale' => $request->getLocale()]);
                }
                $this->em->remove($m);
                $this->em->flush();
                $this->addFlash('success', 'team.flash.member_removed');
                return $this->redirectToRoute('dashboard_team', ['_locale' => $request->getLocale()]);
            }
        }
        throw new NotFoundHttpException();
    }

    #[Route('/leave', name: 'dashboard_team_leave', methods: ['POST'])]
    public function leave(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);
        $member = $this->context->memberFor($user, $workspace);

        if (!$this->isCsrfTokenValid('team-leave', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_team', ['_locale' => $request->getLocale()]);
        }
        if ($member->isOwner()) {
            $this->addFlash('error', 'team.owner_cannot_leave');
            return $this->redirectToRoute('dashboard_team', ['_locale' => $request->getLocale()]);
        }
        $this->em->remove($member);
        $this->em->flush();
        $this->addFlash('success', 'team.flash.left');
        return $this->redirectToRoute('dashboard_home', ['_locale' => $request->getLocale()]);
    }
}
