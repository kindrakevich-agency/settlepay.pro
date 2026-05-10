<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Search-engine and locale-routing endpoints:
 *   /              → 302 to /<locale>/  based on Accept-Language
 *   /robots.txt    → bot directives
 *   /sitemap.xml   → multilingual sitemap with hreflang annotations
 *
 * Splitting these out of the marketing controller keeps them
 * version-controlled (vs. shoving robots.txt into public/) and
 * lets us regenerate sitemap as the route table grows.
 */
class SeoController extends AbstractController
{
    private const SUPPORTED_LOCALES = ['en', 'uk', 'es'];

    /**
     * Bare `/` (no locale) — pick the best locale from Accept-Language and
     * 302 there. Search engines see this and follow to /en/ etc., so each
     * locale URL is the canonical for its language.
     */
    #[Route(path: '/', name: 'root_locale_redirect', methods: ['GET'], priority: 100)]
    public function rootRedirect(Request $request): RedirectResponse
    {
        $locale = $request->getPreferredLanguage(self::SUPPORTED_LOCALES) ?? 'en';
        return $this->redirectToRoute('marketing_home', ['_locale' => $locale], Response::HTTP_FOUND);
    }

    /** /robots.txt — simple, generated dynamically so we can adjust per-env. */
    #[Route(path: '/robots.txt', name: 'seo_robots', methods: ['GET'])]
    public function robots(): Response
    {
        $body = <<<TXT
        User-agent: *
        Allow: /
        Disallow: /api/
        Disallow: /pay/
        Disallow: /*/pay/
        Disallow: /app/
        Disallow: /*/app/
        Disallow: /login
        Disallow: /*/login
        Disallow: /register
        Disallow: /*/register
        Disallow: /forgot-password
        Disallow: /*/forgot-password
        Disallow: /reset-password/
        Disallow: /*/reset-password/
        Disallow: /verify-email/
        Disallow: /*/verify-email/

        Sitemap: https://settlepay.pro/sitemap.xml
        TXT;
        // Strip Heredoc indentation
        $body = preg_replace('/^ {8}/m', '', $body);

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * /sitemap.xml — every public URL × every supported locale, with
     * hreflang annotations so Google can map them as language alternates.
     */
    #[Route(path: '/sitemap.xml', name: 'seo_sitemap', methods: ['GET'])]
    public function sitemap(UrlGeneratorInterface $urls): Response
    {
        // Public, indexable URLs only. Auth/checkout/dashboard never go here.
        $publicRoutes = [
            ['route' => 'marketing_home',    'priority' => '1.0', 'changefreq' => 'weekly'],
            ['route' => 'marketing_pricing', 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['route' => 'marketing_privacy', 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['route' => 'marketing_terms',   'priority' => '0.3', 'changefreq' => 'yearly'],
        ];

        $today = (new \DateTimeImmutable())->format('Y-m-d');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        foreach ($publicRoutes as $r) {
            foreach (self::SUPPORTED_LOCALES as $locale) {
                $loc = $urls->generate($r['route'], ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);

                $xml .= "  <url>\n";
                $xml .= "    <loc>{$loc}</loc>\n";
                $xml .= "    <lastmod>{$today}</lastmod>\n";
                $xml .= "    <changefreq>{$r['changefreq']}</changefreq>\n";
                $xml .= "    <priority>{$r['priority']}</priority>\n";

                // Each URL declares all language alternates including itself.
                foreach (self::SUPPORTED_LOCALES as $altLocale) {
                    $altUrl = $urls->generate($r['route'], ['_locale' => $altLocale], UrlGeneratorInterface::ABSOLUTE_URL);
                    $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"{$altLocale}\" href=\"{$altUrl}\"/>\n";
                }
                $enUrl = $urls->generate($r['route'], ['_locale' => 'en'], UrlGeneratorInterface::ABSOLUTE_URL);
                $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"{$enUrl}\"/>\n";
                $xml .= "  </url>\n";
            }
        }

        $xml .= '</urlset>' . "\n";

        return new Response($xml, 200, [
            'Content-Type'  => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
