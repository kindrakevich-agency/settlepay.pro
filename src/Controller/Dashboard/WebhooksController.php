<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Entity\Webhook;
use App\Repository\WebhookRepository;
use App\Service\Workspace\WorkspaceContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pro-tier webhook management — Owner-only, workspace-scoped.
 */
#[IsGranted('ROLE_USER')]
#[Route('/{_locale}/app/settings/webhooks', requirements: ['_locale' => 'en|uk|es'], defaults: ['_locale' => 'en'])]
class WebhooksController extends AbstractController
{
    public function __construct(
        private readonly WebhookRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly WorkspaceContext $context,
    ) {}

    #[Route('', name: 'dashboard_webhooks', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);

        $justCreated = $request->getSession()->getFlashBag()->get('webhook_secret');

        return $this->render('dashboard/settings/webhooks.html.twig', [
            'workspace'    => $workspace,
            'is_owner'     => $this->context->isOwner($user, $workspace),
            'webhooks'     => $this->repo->findActiveForWorkspace($workspace),
            'all_events'   => Webhook::ALL_EVENTS,
            'just_created' => $justCreated[0] ?? null,
        ]);
    }

    #[Route('', name: 'dashboard_webhooks_create', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);

        if (!$this->isCsrfTokenValid('webhook-create', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_webhooks', ['_locale' => $request->getLocale()]);
        }
        if (!$workspace->isPro()) {
            $this->addFlash('error', 'webhooks.free_plan_required');
            return $this->redirectToRoute('dashboard_webhooks', ['_locale' => $request->getLocale()]);
        }
        if (!$this->context->isOwner($user, $workspace)) {
            $this->addFlash('error', 'webhooks.owner_only');
            return $this->redirectToRoute('dashboard_webhooks', ['_locale' => $request->getLocale()]);
        }

        $url = trim((string) $request->request->get('url', ''));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
            $this->addFlash('error', 'webhooks.invalid_url');
            return $this->redirectToRoute('dashboard_webhooks', ['_locale' => $request->getLocale()]);
        }

        $events = array_values(array_intersect(
            Webhook::ALL_EVENTS,
            (array) $request->request->all('events')
        ));
        if (!$events) {
            $this->addFlash('error', 'webhooks.no_events');
            return $this->redirectToRoute('dashboard_webhooks', ['_locale' => $request->getLocale()]);
        }

        $secret = $this->makeSecret();

        $webhook = (new Webhook())
            ->setUser($user)
            ->setWorkspace($workspace)
            ->setUrl($url)
            ->setSigningSecret($secret)
            ->setEvents($events)
            ->setIsActive(true);

        $this->em->persist($webhook);
        $this->em->flush();

        $this->addFlash('webhook_secret', $secret);
        $this->addFlash('success', 'webhooks.flash.created');
        return $this->redirectToRoute('dashboard_webhooks', ['_locale' => $request->getLocale()]);
    }

    #[Route('/{id}/delete', name: 'dashboard_webhooks_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);

        if (!$this->isCsrfTokenValid('webhook-delete', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_webhooks', ['_locale' => $request->getLocale()]);
        }
        if (!$this->context->isOwner($user, $workspace)) {
            $this->addFlash('error', 'webhooks.owner_only');
            return $this->redirectToRoute('dashboard_webhooks', ['_locale' => $request->getLocale()]);
        }

        $webhook = $this->repo->find($id);
        if (!$webhook || $webhook->getWorkspace()?->getId() !== $workspace->getId()) {
            throw new NotFoundHttpException();
        }
        $this->em->remove($webhook);
        $this->em->flush();

        $this->addFlash('success', 'webhooks.flash.deleted');
        return $this->redirectToRoute('dashboard_webhooks', ['_locale' => $request->getLocale()]);
    }

    /** 64 base64url chars ≈ 384 bits of entropy. */
    private function makeSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }
}
