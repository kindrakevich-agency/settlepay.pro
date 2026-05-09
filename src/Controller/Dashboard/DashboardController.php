<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Authenticated landing page. The full React SPA mounts here in the
 * next milestone; for now this is a placeholder that proves the
 * security firewall + form_login work end-to-end.
 */
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    #[Route(
        path: '/{_locale}/app',
        name: 'dashboard_home',
        requirements: ['_locale' => 'en|uk|es'],
        defaults: ['_locale' => 'en'],
        methods: ['GET'],
    )]
    public function home(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('dashboard/home.html.twig', [
            'user'                => $user,
            'verification_needed' => !$user->isEmailVerified(),
        ]);
    }
}
