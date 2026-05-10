<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Repository\InvoiceRepository;
use App\Service\Blockchain\ChainRegistry;
use App\Service\Invoice\InvoiceFactory;
use App\Service\Invoice\InvoiceMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/{_locale}/app/invoices', requirements: ['_locale' => 'en|uk|es'], defaults: ['_locale' => 'en'])]
class InvoiceController extends AbstractController
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceFactory $factory,
        private readonly InvoiceMailer $mailer,
        private readonly ChainRegistry $chains,
        private readonly EntityManagerInterface $em,
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

                $this->addFlash('success', sprintf('Invoice %s created as draft.', $invoice->getNumber()));
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
            ? sprintf('Invoice %s sent to %s.', $invoice->getNumber(), $invoice->getClientEmail())
            : sprintf('Invoice %s marked sent. (No client email — copy the payment link below to share.)', $invoice->getNumber())
        );

        return $this->redirectToRoute('dashboard_invoice_show', ['_locale' => $request->getLocale(), 'uuid' => $uuid]);
    }
}
