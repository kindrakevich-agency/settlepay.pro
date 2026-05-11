<?php

namespace App\Controller\Dashboard;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Service\Api\ApiTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pro-tier API token management.
 *
 *   GET    /app/settings/api-tokens                 list + create form
 *   POST   /app/settings/api-tokens                 mint (Pro only)
 *   POST   /app/settings/api-tokens/{id}/revoke     revoke
 */
#[IsGranted('ROLE_USER')]
#[Route('/{_locale}/app/settings/api-tokens', requirements: ['_locale' => 'en|uk|es'], defaults: ['_locale' => 'en'])]
class ApiTokensController extends AbstractController
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly ApiTokenRepository $repo,
    ) {}

    #[Route('', name: 'dashboard_api_tokens', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // One-time plaintext lives in the flash bag — shown ONCE then cleared.
        $justCreated = $request->getSession()->getFlashBag()->get('api_token_plaintext');

        return $this->render('dashboard/settings/api_tokens.html.twig', [
            'user'          => $user,
            'tokens'        => $this->repo->findActiveForUser($user),
            'just_created'  => $justCreated[0] ?? null,
        ]);
    }

    #[Route('', name: 'dashboard_api_tokens_create', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('api-token-create', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_api_tokens', ['_locale' => $request->getLocale()]);
        }

        if (!$user->isPro()) {
            $this->addFlash('error', 'api_tokens.free_plan_required');
            return $this->redirectToRoute('dashboard_api_tokens', ['_locale' => $request->getLocale()]);
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '' || mb_strlen($name) > 80) {
            $this->addFlash('error', 'api_tokens.invalid_name');
            return $this->redirectToRoute('dashboard_api_tokens', ['_locale' => $request->getLocale()]);
        }

        $result = $this->tokens->generate($user, $name);
        $this->addFlash('api_token_plaintext', $result['plaintext']);
        $this->addFlash('success', 'api_tokens.flash.created');

        return $this->redirectToRoute('dashboard_api_tokens', ['_locale' => $request->getLocale()]);
    }

    #[Route('/{id}/revoke', name: 'dashboard_api_tokens_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(int $id, Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('api-token-revoke', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_api_tokens', ['_locale' => $request->getLocale()]);
        }

        /** @var ApiToken|null $token */
        $token = $this->repo->find($id);
        if (!$token || $token->getUser()->getId() !== $user->getId()) {
            throw new NotFoundHttpException();
        }
        $this->tokens->revoke($token);

        $this->addFlash('success', 'api_tokens.flash.revoked');
        return $this->redirectToRoute('dashboard_api_tokens', ['_locale' => $request->getLocale()]);
    }
}
