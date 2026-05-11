<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Service\Workspace\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * In-dashboard help — a single-page FAQ + how-to, fully translated.
 *
 * Lives at /{_locale}/app/help. Linked from the sidebar.
 *
 * Content is in translations/messages.{en,uk,es}.yaml under the `help`
 * namespace — copy lives there so each section gets translated cleanly
 * and the page itself is a thin shell.
 */
#[IsGranted('ROLE_USER')]
class HelpController extends AbstractController
{
    public function __construct(private readonly WorkspaceContext $context) {}

    #[Route(
        path: '/{_locale}/app/help',
        name: 'dashboard_help',
        requirements: ['_locale' => 'en|uk|es'],
        defaults: ['_locale' => 'en'],
        methods: ['GET'],
    )]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);
        return $this->render('dashboard/help.html.twig', [
            'user'      => $user,
            'workspace' => $workspace,
        ]);
    }
}
