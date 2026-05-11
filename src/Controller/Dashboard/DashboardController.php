<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Repository\InvoiceRepository;
use App\Service\Workspace\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly WorkspaceContext $context,
    ) {}

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
        $workspace = $this->context->current($user);

        $monthStart    = new \DateTimeImmutable('first day of this month 00:00:00');
        $paidThisMonth = $this->invoices->sumPaidSince($workspace, $monthStart);
        $awaitingTotal = $this->invoices->sumAwaiting($workspace);
        $awaitingBreak = $this->invoices->awaitingBreakdown($workspace);
        $avgSettleSec  = $this->invoices->avgSettleTimeSeconds($workspace);

        return $this->render('dashboard/home.html.twig', [
            'user'                => $user,
            'workspace'           => $workspace,
            'verification_needed' => !$user->isEmailVerified(),
            'metrics' => [
                'paid_this_month_cents' => $paidThisMonth,
                'awaiting_cents'        => $awaitingTotal,
                'awaiting_count'        => $awaitingBreak['total'],
                'awaiting_overdue'      => $awaitingBreak['overdue'],
                'avg_settle_seconds'    => $avgSettleSec,
            ],
            'recent_invoices' => $this->invoices->findRecent($workspace, 5),
            'invoice_count'   => $this->invoices->countByWorkspace($workspace),
        ]);
    }
}
