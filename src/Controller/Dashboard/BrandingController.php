<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Service\Branding\BrandingUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pro-tier custom branding management:
 *
 *   GET  /app/settings/branding            — page with current logo / color
 *   POST /app/settings/branding            — save (color, optional logo upload)
 *   POST /app/settings/branding/remove     — drop logo file + brand_logo_path
 */
#[IsGranted('ROLE_USER')]
#[Route('/{_locale}/app/settings/branding', requirements: ['_locale' => 'en|uk|es'], defaults: ['_locale' => 'en'])]
class BrandingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BrandingUploader $uploader,
    ) {}

    #[Route('', name: 'dashboard_branding', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/settings/branding.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    #[Route('', name: 'dashboard_branding_save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('branding-save', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_branding', ['_locale' => $request->getLocale()]);
        }
        if (!$user->isPro()) {
            $this->addFlash('error', 'branding.free_plan_required');
            return $this->redirectToRoute('dashboard_branding', ['_locale' => $request->getLocale()]);
        }

        // ─── Color (always trim, drop if empty) ───
        $color = trim((string) $request->request->get('brand_color', ''));
        if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $this->addFlash('error', 'branding.invalid_color');
            return $this->redirectToRoute('dashboard_branding', ['_locale' => $request->getLocale()]);
        }
        $user->setBrandColor($color !== '' ? strtolower($color) : null);

        // ─── Optional logo upload ───
        /** @var UploadedFile|null $logo */
        $logo = $request->files->get('brand_logo');
        if ($logo instanceof UploadedFile && $logo->isValid()) {
            // Remove any previous logo first so .png → .svg replacements don't leave orphans.
            $this->uploader->delete($user);
            try {
                $path = $this->uploader->store($user, $logo);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->redirectToRoute('dashboard_branding', ['_locale' => $request->getLocale()]);
            }
            $user->setBrandLogoPath($path);
        }

        $user->touch();
        $this->em->flush();

        $this->addFlash('success', 'branding.flash.saved');
        return $this->redirectToRoute('dashboard_branding', ['_locale' => $request->getLocale()]);
    }

    #[Route('/remove', name: 'dashboard_branding_remove', methods: ['POST'])]
    public function remove(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('branding-remove', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_branding', ['_locale' => $request->getLocale()]);
        }
        $this->uploader->delete($user);
        $user->setBrandLogoPath(null)->touch();
        $this->em->flush();

        $this->addFlash('success', 'branding.flash.logo_removed');
        return $this->redirectToRoute('dashboard_branding', ['_locale' => $request->getLocale()]);
    }
}
