<?php

namespace App\Twig;

use App\Security\AdminChecker;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `is_admin()` to Twig so the dashboard sidebar can conditionally
 * render the Admin link without leaking the route to non-admins.
 */
class AdminExtension extends AbstractExtension
{
    public function __construct(private readonly AdminChecker $admin) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_admin', fn(): bool => $this->admin->isAdmin()),
        ];
    }
}
