<?php

namespace App\Controller\Dashboard;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Placeholder route so the sidebar's "Payments" link resolves until
 * the real payments table ships. Settings has its own controller now.
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
}
