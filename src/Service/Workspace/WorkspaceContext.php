<?php

namespace App\Service\Workspace;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Repository\WorkspaceMemberRepository;
use App\Repository\WorkspaceRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * "Which workspace is the current request acting on?"
 *
 * Resolution order:
 *   1. `?ws=<uuid>` query param OR `active_workspace` session key —
 *      used by the workspace switcher when a user belongs to >1.
 *   2. The user's first owned workspace (every user has one after the
 *      backfill migration).
 *
 * The selected workspace is cached on the request so repeated calls
 * within a single request don't re-hit the DB.
 */
class WorkspaceContext
{
    private const SESSION_KEY = 'active_workspace_uuid';

    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly WorkspaceMemberRepository $members,
        private readonly RequestStack $requestStack,
    ) {}

    /**
     * The workspace acting in this request. Throws if the user is
     * unauthenticated, has no workspaces, or asked for one they don't
     * belong to.
     */
    public function current(User $user): Workspace
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request && $request->attributes->has('_resolved_workspace')) {
            return $request->attributes->get('_resolved_workspace');
        }

        $candidates = $this->workspaces->findForUser($user);
        if (!$candidates) {
            throw new \LogicException('User has no workspaces — backfill migration missing?');
        }

        $picked = null;

        // 1. ?ws=<uuid>
        $wsParam = $request?->query->get('ws');
        $wsSession = $request?->getSession()?->get(self::SESSION_KEY);
        foreach ([$wsParam, $wsSession] as $uuid) {
            if (!$uuid) continue;
            foreach ($candidates as $w) {
                if ($w->getUuid() === $uuid) { $picked = $w; break 2; }
            }
        }

        // 2. fallback to first (chronologically earliest = the user's solo workspace)
        $picked ??= $candidates[0];

        $request?->attributes->set('_resolved_workspace', $picked);
        return $picked;
    }

    public function memberFor(User $user, Workspace $workspace): WorkspaceMember
    {
        $m = $this->members->findFor($workspace, $user);
        if (!$m) {
            throw new AccessDeniedException('Not a member of this workspace.');
        }
        return $m;
    }

    public function isOwner(User $user, Workspace $workspace): bool
    {
        $m = $this->members->findFor($workspace, $user);
        return $m !== null && $m->isOwner();
    }

    /** @throws AccessDeniedException */
    public function requireOwner(User $user, Workspace $workspace): void
    {
        if (!$this->isOwner($user, $workspace)) {
            throw new AccessDeniedException('Workspace owner role required.');
        }
    }

    /** Switch the active workspace for the current session. */
    public function switchTo(string $uuid, User $user): void
    {
        $candidates = $this->workspaces->findForUser($user);
        foreach ($candidates as $w) {
            if ($w->getUuid() === $uuid) {
                $this->requestStack->getSession()?->set(self::SESSION_KEY, $uuid);
                return;
            }
        }
        throw new AccessDeniedException('Not a member of that workspace.');
    }

    /** @return Workspace[] */
    public function listFor(User $user): array
    {
        return $this->workspaces->findForUser($user);
    }
}
