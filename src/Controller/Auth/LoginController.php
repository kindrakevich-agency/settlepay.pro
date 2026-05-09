<?php

namespace App\Controller\Auth;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * The login form is rendered here; submission is handled by Symfony's
 * form_login firewall (see config/packages/security.yaml). Logout is
 * routed but never reaches this controller — Symfony intercepts.
 */
class LoginController extends AbstractController
{
    #[Route(
        path: '/{_locale}/login',
        name: 'auth_login',
        requirements: ['_locale' => 'en|uk|es'],
        defaults: ['_locale' => 'en'],
        methods: ['GET', 'POST'],
    )]
    public function login(AuthenticationUtils $authUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard_home');
        }

        return $this->render('auth/login.html.twig', [
            'last_email' => $authUtils->getLastUsername(),
            'error'      => $authUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route(
        path: '/{_locale}/logout',
        name: 'auth_logout',
        requirements: ['_locale' => 'en|uk|es'],
        defaults: ['_locale' => 'en'],
        methods: ['GET'],
    )]
    public function logout(): never
    {
        // Intercepted by the firewall — this method is never executed.
        throw new \LogicException('Should be intercepted by Symfony Security.');
    }
}
