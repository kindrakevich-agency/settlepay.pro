<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `google_auth_enabled()` and `google_client_id()` to Twig so
 * the auth pages (and home One Tap) can conditionally render the GIS
 * button without leaking the client id into the bundle when the
 * feature is off.
 */
class GoogleAuthExtension extends AbstractExtension
{
    public function __construct(
        private readonly bool $googleAuthEnabled,
        private readonly string $googleClientId,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('google_auth_enabled', fn(): bool => $this->googleAuthEnabled && $this->googleClientId !== ''),
            new TwigFunction('google_client_id', fn(): string => $this->googleClientId),
        ];
    }
}
