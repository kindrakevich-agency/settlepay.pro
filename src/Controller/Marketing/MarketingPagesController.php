<?php

namespace App\Controller\Marketing;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public marketing pages.
 *
 *   /pricing  — Free / Pro / Agency tier comparison + FAQ
 *   /privacy  — privacy policy
 *   /terms    — terms of service
 *
 * Locale-prefixed so SEO hreflang picks them up like the home page does.
 */
class MarketingPagesController extends AbstractController
{
    private const LOCALE_REQUIREMENTS = ['_locale' => 'en|uk|es'];

    #[Route(path: '/{_locale}/pricing', name: 'marketing_pricing', requirements: self::LOCALE_REQUIREMENTS, methods: ['GET'])]
    public function pricing(): Response
    {
        return $this->render('marketing/pricing.html.twig');
    }

    #[Route(path: '/{_locale}/privacy', name: 'marketing_privacy', requirements: self::LOCALE_REQUIREMENTS, methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('marketing/privacy.html.twig', [
            'last_updated' => '2026-05-10',
        ]);
    }

    #[Route(path: '/{_locale}/terms', name: 'marketing_terms', requirements: self::LOCALE_REQUIREMENTS, methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('marketing/terms.html.twig', [
            'last_updated' => '2026-05-10',
        ]);
    }

    #[Route(path: '/{_locale}/docs', name: 'marketing_docs', requirements: self::LOCALE_REQUIREMENTS, methods: ['GET'])]
    public function docsIndex(): Response
    {
        return $this->render('marketing/docs/index.html.twig');
    }

    #[Route(path: '/{_locale}/docs/webhooks', name: 'marketing_docs_webhooks', requirements: self::LOCALE_REQUIREMENTS, methods: ['GET'])]
    public function docsWebhooks(): Response
    {
        return $this->render('marketing/docs/webhooks.html.twig');
    }

    #[Route(path: '/{_locale}/docs/api', name: 'marketing_docs_api', requirements: self::LOCALE_REQUIREMENTS, methods: ['GET'])]
    public function docsApi(): Response
    {
        return $this->render('marketing/docs/api.html.twig');
    }
}
