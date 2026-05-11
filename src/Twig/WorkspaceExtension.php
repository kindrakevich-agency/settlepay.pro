<?php

namespace App\Twig;

use App\Entity\User;
use App\Entity\Workspace;
use App\Service\Workspace\WorkspaceContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `current_workspace()` to Twig — used by the dashboard
 * layout + nav + any template that needs to read business / branding /
 * plan info for the active workspace.
 *
 * Resolution happens on first call per request; subsequent calls hit
 * the same cached resolution via WorkspaceContext.
 */
class WorkspaceExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly WorkspaceContext $context,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_workspace', [$this, 'currentWorkspace']),
            new TwigFunction('is_workspace_owner', [$this, 'isWorkspaceOwner']),
            new TwigFunction('user_workspaces', [$this, 'userWorkspaces']),
        ];
    }

    public function currentWorkspace(): ?Workspace
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) return null;
        try {
            return $this->context->current($user);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isWorkspaceOwner(): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) return false;
        $ws = $this->currentWorkspace();
        return $ws ? $this->context->isOwner($user, $ws) : false;
    }

    /** @return Workspace[] */
    public function userWorkspaces(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) return [];
        return $this->context->listFor($user);
    }
}
