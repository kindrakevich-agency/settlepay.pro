<?php

namespace App\Controller\Marketing;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public legal + pricing pages.
 *
 *   /pricing  — Free / Pro / Agency tier comparison + FAQ
 *   /privacy  — privacy policy (DRAFT pending counsel review)
 *   /terms    — terms of service (DRAFT pending counsel review)
 *
 * All three are static-content Twig renders with no DB hits, so we put
 * them in one controller. They're locale-prefixed so SEO hreflang
 * picks them up like the home page does.
 */
class MarketingPagesController extends AbstractController
{
    private const LOCALE_REQUIREMENTS = ['_locale' => 'en|uk|es'];

    #[Route(path: '/{_locale}/pricing', name: 'marketing_pricing', requirements: self::LOCALE_REQUIREMENTS, defaults: ['_locale' => 'en'], methods: ['GET'])]
    public function pricing(): Response
    {
        return $this->render('marketing/pricing.html.twig');
    }

    #[Route(path: '/{_locale}/privacy', name: 'marketing_privacy', requirements: self::LOCALE_REQUIREMENTS, defaults: ['_locale' => 'en'], methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('marketing/privacy.html.twig', [
            'last_updated' => '2026-05-10',
        ]);
    }

    #[Route(path: '/{_locale}/terms', name: 'marketing_terms', requirements: self::LOCALE_REQUIREMENTS, defaults: ['_locale' => 'en'], methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('marketing/terms.html.twig', [
            'last_updated' => '2026-05-10',
        ]);
    }

    #[Route(path: '/{_locale}/docs', name: 'marketing_docs', requirements: self::LOCALE_REQUIREMENTS, defaults: ['_locale' => 'en'], methods: ['GET'])]
    public function docs(): Response
    {
        return $this->render('marketing/docs.html.twig');
    }
}
