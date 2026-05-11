<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Auth\AddressValidator;
use App\Service\Blockchain\ChainRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 *   GET   /api/v1/me   — current account
 *   PATCH /api/v1/me   — update profile / payout
 */
#[IsGranted('ROLE_USER')]
#[Route('/api/v1/me')]
class MeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AddressValidator $addresses,
        private readonly ChainRegistry $chains,
    ) {}

    #[Route('', name: 'api_me_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        return ApiResponse::ok(ApiResponse::userToArray($user));
    }

    #[Route('', name: 'api_me_patch', methods: ['PATCH'])]
    public function patch(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $body = json_decode($request->getContent(), true) ?? [];
        if (!is_array($body)) {
            return ApiResponse::error('request.invalid_json', 'Body must be JSON.', 400);
        }

        foreach (['display_name', 'business_name', 'business_address', 'tax_id'] as $field) {
            if (array_key_exists($field, $body)) {
                $value = $body[$field];
                $value = $value === null ? null : trim((string) $value);
                $value = $value === '' ? null : $value;
                $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                $user->{$setter}($value);
            }
        }
        if (isset($body['default_currency']))  $user->setDefaultCurrency(strtoupper((string) $body['default_currency']));
        if (isset($body['default_locale']))    $user->setDefaultLocale((string) $body['default_locale']);

        if (isset($body['payout_address'])) {
            try {
                $user->setPayoutAddress($this->addresses->normalize((string) $body['payout_address']));
            } catch (\InvalidArgumentException $e) {
                return ApiResponse::error('wallet.invalid_address', $e->getMessage(), 422);
            }
        }
        if (isset($body['payout_chain_id'])) {
            $chainId = (int) $body['payout_chain_id'];
            if (!$this->chains->getChainById($chainId)) {
                return ApiResponse::error('chain.unsupported', 'Chain is not supported.', 422);
            }
            $user->setPayoutChainId($chainId);
        }
        if (isset($body['payout_token'])) {
            $token = strtoupper((string) $body['payout_token']);
            if (!in_array($token, ['USDC', 'USDT', 'DAI'], true)) {
                return ApiResponse::error('token.unsupported', 'Token is not supported.', 422);
            }
            $user->setPayoutToken($token);
        }

        $user->touch();
        $this->em->flush();

        return ApiResponse::ok(ApiResponse::userToArray($user));
    }
}
