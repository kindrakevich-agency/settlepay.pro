<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Auth\AddressValidator;
use App\Service\Blockchain\ChainRegistry;
use App\Service\Workspace\WorkspaceContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 *   GET   /api/v1/me   — current account (user + active workspace)
 *   PATCH /api/v1/me   — update user-level (display_name, locale) and
 *                        Owner-only workspace-level (business, payout) fields.
 */
#[IsGranted('ROLE_USER')]
#[Route('/api/v1/me')]
class MeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AddressValidator $addresses,
        private readonly ChainRegistry $chains,
        private readonly WorkspaceContext $context,
    ) {}

    #[Route('', name: 'api_me_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);
        return ApiResponse::ok(ApiResponse::userToArray($user, $workspace, $this->context->isOwner($user, $workspace)));
    }

    #[Route('', name: 'api_me_patch', methods: ['PATCH'])]
    public function patch(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);
        $isOwner = $this->context->isOwner($user, $workspace);

        $body = json_decode($request->getContent(), true) ?? [];
        if (!is_array($body)) {
            return ApiResponse::error('request.invalid_json', 'Body must be JSON.', 400);
        }

        // User-level fields (always editable by the calling user).
        if (array_key_exists('display_name', $body)) {
            $v = $body['display_name'];
            $user->setDisplayName($v === null ? null : (trim((string) $v) ?: null));
        }
        if (isset($body['default_locale']))    $user->setDefaultLocale((string) $body['default_locale']);

        // Workspace-level fields — Owner only.
        if ($isOwner) {
            foreach (['business_name', 'business_address', 'tax_id'] as $field) {
                if (array_key_exists($field, $body)) {
                    $value = $body[$field];
                    $value = $value === null ? null : trim((string) $value);
                    $value = $value === '' ? null : $value;
                    $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                    $workspace->{$setter}($value);
                }
            }
            if (isset($body['default_currency']))  $workspace->setDefaultCurrency(strtoupper((string) $body['default_currency']));

            if (isset($body['payout_address'])) {
                try {
                    $workspace->setPayoutAddress($this->addresses->normalize((string) $body['payout_address']));
                } catch (\InvalidArgumentException $e) {
                    return ApiResponse::error('wallet.invalid_address', $e->getMessage(), 422);
                }
            }
            if (isset($body['payout_chain_id'])) {
                $chainId = (int) $body['payout_chain_id'];
                if (!$this->chains->getChainById($chainId)) {
                    return ApiResponse::error('chain.unsupported', 'Chain is not supported.', 422);
                }
                $workspace->setPayoutChainId($chainId);
            }
            if (isset($body['payout_token'])) {
                $token = strtoupper((string) $body['payout_token']);
                if (!in_array($token, ['USDC', 'USDT', 'DAI'], true)) {
                    return ApiResponse::error('token.unsupported', 'Token is not supported.', 422);
                }
                $workspace->setPayoutToken($token);
            }
            $workspace->touch();
        }

        $user->touch();
        $this->em->flush();

        return ApiResponse::ok(ApiResponse::userToArray($user, $workspace, $isOwner));
    }
}
