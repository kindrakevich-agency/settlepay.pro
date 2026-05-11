<?php

namespace App\Service\Workspace;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceInvitation;
use App\Entity\WorkspaceMember;
use App\Repository\UserRepository;
use App\Repository\WorkspaceInvitationRepository;
use App\Repository\WorkspaceMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Create / revoke / accept workspace invitations.
 *
 * Seat enforcement: caller can call ensureCanInvite() to bail before
 * spending an email on an over-quota workspace. accept() also checks
 * the cap server-side so race conditions can't sneak past.
 */
class InvitationManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkspaceInvitationRepository $invitations,
        private readonly WorkspaceMemberRepository $members,
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFromAddress,
        private readonly string $mailerFromName,
    ) {}

    /** @throws \DomainException seat limit reached / already a member */
    public function ensureCanInvite(Workspace $workspace, string $email): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('invitations.invalid_email');
        }

        // Already a member?
        $existingUser = $this->users->findOneBy(['email' => $email]);
        if ($existingUser && $this->members->findFor($workspace, $existingUser)) {
            throw new \DomainException('invitations.already_member');
        }

        // Already a pending invite for this email?
        foreach ($this->invitations->findPendingFor($workspace) as $pending) {
            if ($pending->getEmail() === $email) {
                throw new \DomainException('invitations.already_invited');
            }
        }

        $current  = $this->members->countInWorkspace($workspace);
        $pending  = count($this->invitations->findPendingFor($workspace));
        if ($current + $pending >= $workspace->getSeatLimit()) {
            throw new \DomainException('invitations.seat_limit_reached');
        }
    }

    public function invite(Workspace $workspace, string $email, User $invitedBy, string $role = WorkspaceMember::ROLE_MEMBER): WorkspaceInvitation
    {
        $this->ensureCanInvite($workspace, $email);

        $invitation = (new WorkspaceInvitation())
            ->setWorkspace($workspace)
            ->setEmail($email)
            ->setRole($role)
            ->setInvitedBy($invitedBy);

        $this->em->persist($invitation);
        $this->em->flush();

        $this->sendInviteEmail($invitation);
        return $invitation;
    }

    public function revoke(WorkspaceInvitation $inv): void
    {
        if ($inv->getAcceptedAt() !== null) return; // can't revoke an already-accepted invite
        $inv->markRevoked();
        $this->em->flush();
    }

    /**
     * Apply an invitation to a logged-in user. Caller must verify the
     * token is valid + pending first.
     *
     * @throws \DomainException on email mismatch or seat overflow
     */
    public function accept(WorkspaceInvitation $inv, User $acceptingUser): WorkspaceMember
    {
        if (!$inv->isPending()) {
            throw new \DomainException('invitations.expired_or_used');
        }
        if (strtolower($acceptingUser->getEmail()) !== $inv->getEmail()) {
            throw new \DomainException('invitations.email_mismatch');
        }
        // Re-check seat cap server-side at accept-time.
        $workspace = $inv->getWorkspace();
        if ($this->members->countInWorkspace($workspace) >= $workspace->getSeatLimit()) {
            throw new \DomainException('invitations.seat_limit_reached');
        }

        $member = (new WorkspaceMember())
            ->setWorkspace($workspace)
            ->setUser($acceptingUser)
            ->setRole($inv->getRole());

        $inv->markAccepted();
        $this->em->persist($member);
        $this->em->flush();
        return $member;
    }

    private function sendInviteEmail(WorkspaceInvitation $inv): void
    {
        $acceptUrl = $this->urls->generate('workspaces_invitation_accept', [
            '_locale' => $inv->getInvitedBy()->getDefaultLocale(),
            'token'   => $inv->getToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($inv->getEmail())
            ->subject(sprintf('You have been invited to join %s on Settlepay', $inv->getWorkspace()->getName()))
            ->htmlTemplate('emails/workspaces/invitation.html.twig')
            ->textTemplate('emails/workspaces/invitation.txt.twig')
            ->context([
                'invitation' => $inv,
                'workspace'  => $inv->getWorkspace(),
                'invited_by' => $inv->getInvitedBy(),
                'accept_url' => $acceptUrl,
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Workspace invite email failed', [
                'invitation_id' => $inv->getId(),
                'workspace_id'  => $inv->getWorkspace()->getId(),
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
