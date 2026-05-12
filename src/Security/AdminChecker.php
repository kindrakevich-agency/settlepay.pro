<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Tiny email-allowlist admin check. Driven by ADMIN_EMAILS env var
 * (comma-separated). Keeps the surface intentionally small — no roles
 * column, no DB migration, no UI for granting admin. Add/remove admins
 * by editing .env.local + `bin/console cache:clear`.
 *
 * Membership is checked case-insensitively against the user's email.
 * Non-admins get 404 (not 403) from admin routes so the existence of
 * /app/admin isn't leaked to ordinary users.
 */
final class AdminChecker
{
    /** @var string[] lowercase emails */
    private readonly array $emails;

    public function __construct(
        private readonly Security $security,
        string $adminEmails,
    ) {
        $this->emails = array_filter(array_map(
            fn(string $e) => strtolower(trim($e)),
            explode(',', $adminEmails),
        ));
    }

    public function isAdmin(): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }
        return in_array(strtolower($user->getEmail()), $this->emails, true);
    }
}
