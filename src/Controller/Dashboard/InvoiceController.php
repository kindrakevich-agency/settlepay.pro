<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Repository\InvoiceRepository;
use App\Service\Blockchain\ChainRegistry;
use App\Service\Invoice\InvoiceFactory;
use App\Service\Invoice\InvoiceMailer;
use App\Service\Invoice\InvoicePdfRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
#[Route('/{_locale}/app/invoices', requirements: ['_locale' => 'en|uk|es'], defaults: ['_locale' => 'en'])]
class InvoiceController extends AbstractController
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceFactory $factory,
        private readonly InvoiceMailer $mailer,
        private readonly InvoicePdfRenderer $pdf,
        private readonly ChainRegistry $chains,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'dashboard_invoices', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $userId = (int) $user->getId();

        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = 25;
        $status  = (string) $request->query->get('status', '');
        $search  = (string) $request->query->get('q', '');
        $statusFilter = $status !== '' ? $status : null;
        $searchFilter = $search !== '' ? $search : null;

        $items   = $this->invoices->findByUserPaginated($userId, $page, $perPage, $statusFilter, $searchFilter);
        $total   = $this->invoices->countByUser($userId, $statusFilter, $searchFilter);
        $pages   = max(1, (int) ceil($total / $perPage));

        return $this->render('dashboard/invoices/index.html.twig', [
            'invoices'      => $items,
            'invoice_count' => $this->invoices->countByUser($userId),
            'total'         => $total,
            'page'          => $page,
            'pages'         => $pages,
            'status'        => $status,
            'search'        => $search,
            'statuses'      => array_map(fn(InvoiceStatus $s) => $s->value, InvoiceStatus::cases()),
        ]);
    }

    #[Route('/new', name: 'dashboard_invoice_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $errors = [];
        $form   = [
            'client_name'    => '',
            'client_email'   => '',
            'description'    => '',
            'due_date'       => (new \DateTimeImmutable('+14 days'))->format('Y-m-d'),
            'currency'       => $user->getDefaultCurrency(),
            'accepted_chains'=> [(int) $user->getPayoutChainId()],
            'accepted_tokens'=> [$user->getPayoutToken()],
            'line_items'     => [
                ['description' => '', 'quantity' => '1', 'unit_price' => ''],
            ],
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('invoice-new', (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'errors.csrf_invalid';
            }

            // Read posted values
            $form['client_name']   = trim((string) $request->request->get('client_name', ''));
            $form['client_email']  = trim((string) $request->request->get('client_email', ''));
            $form['description']   = trim((string) $request->request->get('description', ''));
            $form['due_date']      = (string) $request->request->get('due_date', '');
            $form['currency']      = strtoupper((string) $request->request->get('currency', 'USD'));
            $form['accepted_chains'] = array_map('intval', (array) $request->request->all('accepted_chains'));
            $form['accepted_tokens'] = array_map('strtoupper', (array) $request->request->all('accepted_tokens'));

            // Line items
            $rawItems = (array) $request->request->all('line_items');
            $form['line_items'] = [];
            $cleanItems = [];
            foreach ($rawItems as $li) {
                $desc  = trim((string) ($li['description'] ?? ''));
                $qty   = (string) ($li['quantity'] ?? '1');
                $price = trim((string) ($li['unit_price'] ?? ''));
                $form['line_items'][] = ['description' => $desc, 'quantity' => $qty, 'unit_price' => $price];
                if ($desc === '' && $price === '') continue;
                if ($desc === '') $errors[] = 'errors.line_item_desc_required';
                if (!is_numeric($price) || (float) $price < 0) $errors[] = 'errors.line_item_price_invalid';
                if (!is_numeric($qty)   || (float) $qty   <= 0) $errors[] = 'errors.line_item_qty_invalid';
                if (empty($errors)) {
                    $cleanItems[] = [
                        'description'      => $desc,
                        'quantity'         => $qty,
                        'unit_price_cents' => (int) round((float) $price * 100),
                    ];
                }
            }
            if (empty($form['line_items']) || count($cleanItems) === 0) {
                $errors[] = 'errors.no_line_items';
            }

            if ($form['client_name'] === '')  $errors[] = 'errors.client_name_required';
            if ($form['client_email'] !== '' && !filter_var($form['client_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'errors.invalid_email';
            }
            if (empty($form['accepted_chains'])) $errors[] = 'errors.no_chains';
            if (empty($form['accepted_tokens'])) $errors[] = 'errors.no_tokens';
            if ($user->getPayoutAddress() === '0x0000000000000000000000000000000000000000') {
                $errors[] = 'errors.payout_address_unset';
            }

            // Free-plan $1,000 MTD invoicing cap. Sum of THIS new invoice
            // + everything else issued this month must not exceed $1k.
            // Pro users (including Pro-lifetime) skip the check entirely.
            $totalNewCents = array_sum(array_map(static fn(array $li): int =>
                (int) round((float) bcmul((string)($li['quantity'] ?? '1.00'), (string)$li['unit_price_cents'], 6)),
                $cleanItems
            ));
            if (!$user->isPro()) {
                $cap   = 100000; // $1,000 in cents
                $usage = $this->invoices->monthToDateIssuedCents((int) $user->getId());
                if ($usage + $totalNewCents > $cap) {
                    $errors[] = 'errors.free_volume_cap';
                }
            }

            if (empty($errors)) {
                $invoice = $this->factory->create($user, [
                    'client_name'      => $form['client_name'],
                    'client_email'     => $form['client_email'] ?: null,
                    'description'      => $form['description'] ?: null,
                    'currency'         => $form['currency'],
                    'due_date'         => $form['due_date'] ?: null,
                    'accepted_chains'  => $form['accepted_chains'],
                    'accepted_tokens'  => $form['accepted_tokens'],
                    'line_items'       => $cleanItems,
                ]);

                $this->addFlash('success', $this->translator->trans('flash.invoice_created_draft', ['%number%' => $invoice->getNumber()]));
                return $this->redirectToRoute('dashboard_invoice_show', [
                    '_locale' => $request->getLocale(),
                    'uuid'    => $invoice->getUuid(),
                ]);
            }
        }

        return $this->render('dashboard/invoices/new.html.twig', [
            'form'           => $form,
            'errors'         => $errors,
            'available_chains' => $this->chains->getMainnets() + $this->chains->getTestnets(),
        ]);
    }

    #[Route('/{uuid}', name: 'dashboard_invoice_show',
        requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['GET'])]
    public function show(string $uuid): Response
    {
        $invoice = $this->invoices->findByUuid($uuid);
        if (!$invoice || $invoice->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Invoice not found.');
        }
        return $this->render('dashboard/invoices/show.html.twig', [
            'invoice' => $invoice,
        ]);
    }

    #[Route('/{uuid}/edit', name: 'dashboard_invoice_edit',
        requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['GET', 'POST'])]
    public function edit(string $uuid, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $invoice = $this->invoices->findByUuid($uuid);
        if (!$invoice || $invoice->getUser() !== $user) {
            throw $this->createNotFoundException('Invoice not found.');
        }
        if ($invoice->getStatus() !== InvoiceStatus::Draft) {
            $this->addFlash('warning', 'errors.invoice_already_sent');
            return $this->redirectToRoute('dashboard_invoice_show', ['_locale' => $request->getLocale(), 'uuid' => $uuid]);
        }

        $errors = [];
        // Pre-fill the form with existing values on first GET.
        $form = [
            'client_name'     => $invoice->getClientName(),
            'client_email'    => $invoice->getClientEmail() ?? '',
            'description'     => $invoice->getDescription() ?? '',
            'due_date'        => $invoice->getDueDate()?->format('Y-m-d') ?? '',
            'currency'        => $invoice->getCurrency(),
            'accepted_chains' => $invoice->getAcceptedChains(),
            'accepted_tokens' => $invoice->getAcceptedTokens(),
            'line_items'      => array_map(fn($li) => [
                'description' => $li->getDescription(),
                'quantity'    => $li->getQuantity(),
                'unit_price'  => number_format($li->getUnitPriceCents() / 100, 2, '.', ''),
            ], $invoice->getLineItems()->toArray()),
        ];
        if (empty($form['line_items'])) {
            $form['line_items'] = [['description' => '', 'quantity' => '1', 'unit_price' => '']];
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('invoice-edit', (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'errors.csrf_invalid';
            }

            $form['client_name']     = trim((string) $request->request->get('client_name', ''));
            $form['client_email']    = trim((string) $request->request->get('client_email', ''));
            $form['description']     = trim((string) $request->request->get('description', ''));
            $form['due_date']        = (string) $request->request->get('due_date', '');
            $form['currency']        = strtoupper((string) $request->request->get('currency', 'USD'));
            $form['accepted_chains'] = array_map('intval', (array) $request->request->all('accepted_chains'));
            $form['accepted_tokens'] = array_map('strtoupper', (array) $request->request->all('accepted_tokens'));

            $rawItems = (array) $request->request->all('line_items');
            $form['line_items'] = [];
            $cleanItems = [];
            foreach ($rawItems as $li) {
                $desc  = trim((string) ($li['description'] ?? ''));
                $qty   = (string) ($li['quantity'] ?? '1');
                $price = trim((string) ($li['unit_price'] ?? ''));
                $form['line_items'][] = ['description' => $desc, 'quantity' => $qty, 'unit_price' => $price];
                if ($desc === '' && $price === '') continue;
                if ($desc === '') $errors[] = 'errors.line_item_desc_required';
                if (!is_numeric($price) || (float) $price < 0) $errors[] = 'errors.line_item_price_invalid';
                if (!is_numeric($qty)   || (float) $qty   <= 0) $errors[] = 'errors.line_item_qty_invalid';
                if (empty($errors)) {
                    $cleanItems[] = [
                        'description'      => $desc,
                        'quantity'         => $qty,
                        'unit_price_cents' => (int) round((float) $price * 100),
                    ];
                }
            }
            if (count($cleanItems) === 0) {
                $errors[] = 'errors.no_line_items';
            }
            if ($form['client_name'] === '')  $errors[] = 'errors.client_name_required';
            if ($form['client_email'] !== '' && !filter_var($form['client_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'errors.invalid_email';
            }
            if (empty($form['accepted_chains'])) $errors[] = 'errors.no_chains';
            if (empty($form['accepted_tokens'])) $errors[] = 'errors.no_tokens';

            // Free-plan $1k MTD cap also covers edits — recompute as
            // (MTD - existing amount + new amount). Skip for Pro.
            if (!$user->isPro()) {
                $cap          = 100000;
                $usage        = $this->invoices->monthToDateIssuedCents((int) $user->getId());
                $totalNewCents = array_sum(array_map(static fn(array $li): int =>
                    (int) round((float) bcmul((string)($li['quantity'] ?? '1.00'), (string)$li['unit_price_cents'], 6)),
                    $cleanItems
                ));
                $projected = $usage - $invoice->getAmountCents() + $totalNewCents;
                if ($projected > $cap) {
                    $errors[] = 'errors.free_volume_cap';
                }
            }

            if (empty($errors)) {
                $this->factory->update($invoice, [
                    'client_name'     => $form['client_name'],
                    'client_email'    => $form['client_email'] ?: null,
                    'description'     => $form['description'] ?: null,
                    'currency'        => $form['currency'],
                    'due_date'        => $form['due_date'] ?: null,
                    'accepted_chains' => $form['accepted_chains'],
                    'accepted_tokens' => $form['accepted_tokens'],
                    'line_items'      => $cleanItems,
                ]);
                $this->addFlash('success', $this->translator->trans('flash.invoice_updated', ['%number%' => $invoice->getNumber()]));
                return $this->redirectToRoute('dashboard_invoice_show', ['_locale' => $request->getLocale(), 'uuid' => $invoice->getUuid()]);
            }
        }

        return $this->render('dashboard/invoices/new.html.twig', [
            'form'             => $form,
            'errors'           => $errors,
            'available_chains' => $this->chains->getMainnets() + $this->chains->getTestnets(),
            'edit_invoice'     => $invoice,
        ]);
    }

    #[Route('/{uuid}/void', name: 'dashboard_invoice_void',
        requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['POST'])]
    public function void(string $uuid, Request $request): RedirectResponse
    {
        $invoice = $this->invoices->findByUuid($uuid);
        if (!$invoice || $invoice->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Invoice not found.');
        }
        if (!$this->isCsrfTokenValid('invoice-void', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_invoice_show', ['_locale' => $request->getLocale(), 'uuid' => $uuid]);
        }
        // Void allowed only on drafts + sent + viewed. Paid / overdue / already-void invoices can't be voided.
        if (!in_array($invoice->getStatus(), [InvoiceStatus::Draft, InvoiceStatus::Sent, InvoiceStatus::Viewed], true)) {
            $this->addFlash('warning', 'errors.cannot_void_after_paid');
            return $this->redirectToRoute('dashboard_invoice_show', ['_locale' => $request->getLocale(), 'uuid' => $uuid]);
        }

        $invoice->setStatus(InvoiceStatus::Void)->touch();
        $this->em->flush();
        $this->addFlash('success', $this->translator->trans('flash.invoice_voided', ['%number%' => $invoice->getNumber()]));
        return $this->redirectToRoute('dashboard_invoices', ['_locale' => $request->getLocale()]);
    }

    #[Route('/{uuid}/pdf', name: 'dashboard_invoice_pdf',
        requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['GET'])]
    public function pdf(string $uuid, Request $request): Response
    {
        $invoice = $this->invoices->findByUuid($uuid);
        if (!$invoice || $invoice->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Invoice not found.');
        }

        $bytes = $this->pdf->render($invoice, $request->getLocale());

        return new Response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $this->pdf->filenameFor($invoice)),
            'Cache-Control'       => 'private, no-cache',
        ]);
    }

    #[Route('/{uuid}/send', name: 'dashboard_invoice_send',
        requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['POST'])]
    public function send(string $uuid, Request $request): RedirectResponse
    {
        $invoice = $this->invoices->findByUuid($uuid);
        if (!$invoice || $invoice->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Invoice not found.');
        }
        if (!$this->isCsrfTokenValid('invoice-send', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'errors.csrf_invalid');
            return $this->redirectToRoute('dashboard_invoice_show', ['_locale' => $request->getLocale(), 'uuid' => $uuid]);
        }
        if ($invoice->getStatus() !== InvoiceStatus::Draft) {
            $this->addFlash('warning', 'errors.invoice_already_sent');
            return $this->redirectToRoute('dashboard_invoice_show', ['_locale' => $request->getLocale(), 'uuid' => $uuid]);
        }

        $invoice->setStatus(InvoiceStatus::Sent)->touch();
        $this->em->flush();

        $emailed = $this->mailer->sendInvoiceToClient($invoice);
        $this->addFlash('success', $emailed
            ? $this->translator->trans('flash.invoice_sent_emailed', ['%number%' => $invoice->getNumber(), '%email%' => $invoice->getClientEmail()])
            : $this->translator->trans('flash.invoice_sent_no_email', ['%number%' => $invoice->getNumber()])
        );

        return $this->redirectToRoute('dashboard_invoice_show', ['_locale' => $request->getLocale(), 'uuid' => $uuid]);
    }
}
