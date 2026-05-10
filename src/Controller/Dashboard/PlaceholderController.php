<?php

namespace App\Controller\Dashboard;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Placeholder routes so the sidebar links resolve. Each shows a
 * "coming soon" panel matching the design system. Replace as the
 * real Payments and Settings pages get built.
 */
#[IsGranted('ROLE_USER')]
class PlaceholderController extends AbstractController
{
    #[Route(
        path: '/{_locale}/app/payments',
        name: 'dashboard_payments',
        requirements: ['_locale' => 'en|uk|es'],
        defaults: ['_locale' => 'en'],
        methods: ['GET'],
    )]
    public function payments(): Response
    {
        return $this->render('dashboard/_coming_soon.html.twig', [
            'page_key'    => 'payments',
            'title_key'   => 'nav.payments',
            'sub_key'     => 'dashboard.coming_soon.payments_sub',
        ]);
    }

    #[Route(
        path: '/{_locale}/app/settings',
        name: 'dashboard_settings',
        requirements: ['_locale' => 'en|uk|es'],
        defaults: ['_locale' => 'en'],
        methods: ['GET'],
    )]
    public function settings(): Response
    {
        return $this->render('dashboard/_coming_soon.html.twig', [
            'page_key'    => 'settings',
            'title_key'   => 'nav.settings',
            'sub_key'     => 'dashboard.coming_soon.settings_sub',
        ]);
    }
}
