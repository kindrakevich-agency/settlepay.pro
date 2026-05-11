<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\PaymentRepository;
use App\Service\Workspace\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/api/v1/payments')]
class PaymentController extends AbstractController
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly WorkspaceContext $context,
    ) {}

    #[Route('', name: 'api_payments_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);

        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));
        $page  = max(1, (int) $request->query->get('page', 1));
        $kind  = (string) $request->query->get('kind', '') ?: null;
        $chain = $request->query->get('chain_id') !== null ? (int) $request->query->get('chain_id') : null;

        $items = $this->payments->findForWorkspacePaginated($workspace, $page, $limit, $kind, $chain);
        $total = $this->payments->countForWorkspace($workspace, $kind, $chain);

        return ApiResponse::ok(
            array_map([ApiResponse::class, 'paymentToArray'], $items),
            ['page' => $page, 'limit' => $limit, 'total' => $total],
        );
    }

    #[Route('/{id}', name: 'api_payments_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspace = $this->context->current($user);

        $payment = $this->payments->find($id);
        if (!$payment) {
            return ApiResponse::error('payment.not_found', 'Payment not found.', 404);
        }
        $invoice = $payment->getInvoice();
        $owned = $invoice
            ? $invoice->getWorkspace()?->getId() === $workspace->getId()
            : strtolower($payment->getRecipientAddress()) === strtolower($workspace->getPayoutAddress());
        if (!$owned) {
            return ApiResponse::error('payment.not_found', 'Payment not found.', 404);
        }
        return ApiResponse::ok(ApiResponse::paymentToArray($payment));
    }
}
