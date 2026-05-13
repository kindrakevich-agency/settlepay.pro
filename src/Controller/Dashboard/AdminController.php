<?php

namespace App\Controller\Dashboard;

use App\Security\AdminChecker;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ops view for the platform owner. Email-allowlist gated via
 * AdminChecker (driven by ADMIN_EMAILS env var). Non-admins 404 so the
 * routes don't leak.
 *
 * All reads use raw DBAL for two reasons:
 *  - aggregate / cross-table queries don't benefit from DQL ergonomics
 *  - the page is read-only so there's no entity hydration to gain from
 */
#[Route('/{_locale}/app/admin', requirements: ['_locale' => 'en|uk|es'], defaults: ['_locale' => 'en'])]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly AdminChecker $admin,
        private readonly Connection $db,
    ) {}

    #[Route('', name: 'dashboard_admin', methods: ['GET'])]
    public function overview(): Response
    {
        $this->assertAdmin();

        $users = (int) $this->db->fetchOne('SELECT COUNT(*) FROM users');
        $usersGoogle = (int) $this->db->fetchOne('SELECT COUNT(*) FROM users WHERE google_sub IS NOT NULL');
        $usersVerified = (int) $this->db->fetchOne('SELECT COUNT(*) FROM users WHERE email_verified_at IS NOT NULL');
        $usersLast7d = (int) $this->db->fetchOne("SELECT COUNT(*) FROM users WHERE created_at > (NOW() - INTERVAL 7 DAY)");

        $workspacesByPlan = $this->db->fetchAllAssociative(
            'SELECT plan, COUNT(*) AS n FROM workspaces GROUP BY plan ORDER BY n DESC'
        );

        $invoicesByStatus = $this->db->fetchAllAssociative(
            'SELECT status, COUNT(*) AS n FROM invoices GROUP BY status ORDER BY n DESC'
        );

        $openInvoices = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM invoices WHERE status IN ('draft','sent','viewed','overdue','partially_paid')"
        );

        $revenue = $this->db->fetchAssociative(
            'SELECT COUNT(*) AS payments, COALESCE(SUM(amount_usd_cents), 0) AS total_cents FROM fee_payments'
        );

        $invoicePaidTotal = (int) $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount_usd_cents), 0) FROM payments WHERE confirmed_at IS NOT NULL"
        );

        $recentUsers = $this->db->fetchAllAssociative(
            'SELECT u.id, u.email, u.google_sub IS NOT NULL AS via_google, u.created_at, u.last_login_at,
                    (SELECT COUNT(*) FROM workspace_members WHERE user_id = u.id) AS workspace_count
             FROM users u
             ORDER BY u.id DESC
             LIMIT 10'
        );

        return $this->render('dashboard/admin/index.html.twig', [
            'users'             => $users,
            'users_google'      => $usersGoogle,
            'users_verified'    => $usersVerified,
            'users_last_7d'     => $usersLast7d,
            'open_invoices'     => $openInvoices,
            'workspaces_by_plan'=> $workspacesByPlan,
            'invoices_by_status'=> $invoicesByStatus,
            'fee_payments'      => $revenue,
            'invoice_paid_cents'=> $invoicePaidTotal,
            'recent_users'      => $recentUsers,
        ]);
    }

    #[Route('/users', name: 'dashboard_admin_users', methods: ['GET'])]
    public function users(): Response
    {
        $this->assertAdmin();

        $rows = $this->db->fetchAllAssociative(
            'SELECT u.id, u.email, u.display_name, u.google_sub, u.email_verified_at, u.created_at, u.last_login_at,
                    (SELECT COUNT(*) FROM workspace_members WHERE user_id = u.id) AS workspace_count,
                    (SELECT GROUP_CONCAT(w.name SEPARATOR ", ") FROM workspaces w
                     JOIN workspace_members m ON m.workspace_id = w.id WHERE m.user_id = u.id) AS workspaces
             FROM users u ORDER BY u.id DESC'
        );

        return $this->render('dashboard/admin/users.html.twig', ['users' => $rows]);
    }

    #[Route('/workspaces', name: 'dashboard_admin_workspaces', methods: ['GET'])]
    public function workspaces(): Response
    {
        $this->assertAdmin();

        $rows = $this->db->fetchAllAssociative(
            'SELECT w.id, w.name, w.plan, w.plan_renews_at, w.seat_limit, w.fees_owed_cents,
                    u.email AS owner_email,
                    (SELECT COUNT(*) FROM workspace_members WHERE workspace_id = w.id) AS members,
                    (SELECT COUNT(*) FROM invoices WHERE workspace_id = w.id) AS invoices,
                    (SELECT COUNT(*) FROM invoices WHERE workspace_id = w.id AND status = "paid") AS invoices_paid,
                    w.created_at
             FROM workspaces w
             JOIN users u ON u.id = w.owner_user_id
             ORDER BY w.id DESC'
        );

        return $this->render('dashboard/admin/workspaces.html.twig', ['workspaces' => $rows]);
    }

    #[Route('/payments', name: 'dashboard_admin_payments', methods: ['GET'])]
    public function payments(): Response
    {
        $this->assertAdmin();

        $fees = $this->db->fetchAllAssociative(
            'SELECT fp.id, fp.amount_usd_cents, fp.chain_id, fp.tx_hash, fp.payer_address, fp.created_at,
                    bi.kind, bi.uuid AS intent_uuid, w.name AS workspace_name
             FROM fee_payments fp
             JOIN billing_intents bi ON bi.id = fp.billing_intent_id
             JOIN workspaces w ON w.id = bi.workspace_id
             ORDER BY fp.id DESC LIMIT 50'
        );

        $invoicePayments = $this->db->fetchAllAssociative(
            'SELECT p.id, p.amount_usd_cents, p.chain_id, p.tx_hash, p.payer_address, p.confirmed_at,
                    i.number AS invoice_number, w.name AS workspace_name
             FROM payments p
             LEFT JOIN invoices i ON i.id = p.invoice_id
             LEFT JOIN workspaces w ON w.id = i.workspace_id
             WHERE p.confirmed_at IS NOT NULL
             ORDER BY p.id DESC LIMIT 50'
        );

        return $this->render('dashboard/admin/payments.html.twig', [
            'fee_payments'     => $fees,
            'invoice_payments' => $invoicePayments,
        ]);
    }

    private function assertAdmin(): void
    {
        if (!$this->admin->isAdmin()) {
            throw $this->createNotFoundException();
        }
    }
}
