<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Repository\PaymentRepository;
use App\Service\Blockchain\ChainRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class PaymentsController extends AbstractController
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly ChainRegistry $chains,
    ) {}

    #[Route(
        path: '/{_locale}/app/payments',
        name: 'dashboard_payments',
        requirements: ['_locale' => 'en|uk|es'],
        defaults: ['_locale' => 'en'],
        methods: ['GET'],
    )]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $userId = (int) $user->getId();
        $addr   = strtolower($user->getPayoutAddress());

        // Filters
        $kind     = (string) $request->query->get('kind', '');
        $chainId  = (int)    $request->query->get('chain', 0);
        $page     = max(1, (int) $request->query->get('page', 1));
        $perPage  = 25;

        $kindFilter  = in_array($kind, ['matched', 'orphan'], true) ? $kind : null;
        $chainFilter = $chainId > 0 ? $chainId : null;

        $items   = $this->payments->findForUserPaginated($userId, $addr, $page, $perPage, $kindFilter, $chainFilter);
        $total   = $this->payments->countForUser($userId, $addr, $kindFilter, $chainFilter);
        $pages   = max(1, (int) ceil($total / $perPage));

        $totalUsd = $this->payments->sumMatchedUsdCentsForUser($userId);

        return $this->render('dashboard/payments/index.html.twig', [
            'payments'  => $items,
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,
            'kind'      => $kind,
            'chain_id'  => $chainId,
            'total_usd_cents' => $totalUsd,
            'all_chains' => $this->chains->getMainnets() + $this->chains->getTestnets(),
        ]);
    }
}
