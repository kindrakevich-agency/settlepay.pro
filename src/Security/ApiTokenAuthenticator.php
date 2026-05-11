<?php

namespace App\Security;

use App\Service\Api\ApiTokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Bearer-token authenticator for `/api/v1/*` programmatic access.
 *
 * Looks for `Authorization: Bearer sk_pro_…`, verifies via
 * {@see ApiTokenService}, returns the owning user.
 */
class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    public function supports(Request $request): ?bool
    {
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return false;
        }
        $auth = $request->headers->get('Authorization', '');
        return str_starts_with($auth, 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $plaintext = trim(substr($request->headers->get('Authorization', ''), 7));
        if ($plaintext === '') {
            throw new CustomUserMessageAuthenticationException('Missing API token.');
        }

        $apiToken = $this->tokens->verify($plaintext);
        if (!$apiToken) {
            throw new CustomUserMessageAuthenticationException('Invalid or revoked API token.');
        }

        $userId = (string) $apiToken->getUser()->getId();
        return new SelfValidatingPassport(new UserBadge($userId, function (string $id) use ($apiToken) {
            // We already have the User loaded via the token relation.
            return $apiToken->getUser();
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null; // pass through to the controller
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'error' => [
                'code'    => 'auth.invalid_token',
                'message' => $exception->getMessage() ?: 'Authentication failed.',
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
