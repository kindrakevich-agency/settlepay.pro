<?php

namespace App\Service\Invoice;

use App\Entity\Invoice;
use App\Entity\InvoiceLineItem;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\InvoiceStatus;
use App\Repository\InvoiceRepository;

/**
 * Builds Invoice entities from controller-supplied DTOs.
 *
 * Centralizes:
 *   - Number generation (delegates to InvoiceNumberGenerator)
 *   - amount_cents = Σ line_item totals (no float math)
 *   - workspace + recipient_address + chain + token defaults
 *
 * Phase 2: the Workspace is the ownership scope; the User passed in
 * is the *creator* (for attribution / created_by). Payout settings,
 * default currency, etc. come from the workspace.
 */
final class InvoiceFactory
{
    public function __construct(
        private readonly InvoiceNumberGenerator $numbers,
        private readonly InvoiceRepository $invoices,
    ) {}

    /**
     * @param array{
     *   client_name:string,
     *   client_email?:?string,
     *   client_address?:?string,
     *   description?:?string,
     *   notes?:?string,
     *   currency?:string,
     *   due_date?:?string,
     *   accepted_chains?:?array<int>,
     *   accepted_tokens?:?array<string>,
     *   line_items: list<array{description:string, quantity?:string, unit_price_cents:int}>
     * } $data
     */
    public function create(Workspace $workspace, User $createdBy, array $data): Invoice
    {
        $invoice = new Invoice();
        $invoice
            ->setUser($createdBy)
            ->setWorkspace($workspace)
            ->setNumber($this->numbers->next($workspace))
            ->setStatus(InvoiceStatus::Draft)
            ->setCurrency($data['currency'] ?? $workspace->getDefaultCurrency())
            ->setClientName(trim((string) $data['client_name']))
            ->setClientEmail(!empty($data['client_email']) ? trim((string) $data['client_email']) : null)
            ->setClientAddress(!empty($data['client_address']) ? trim((string) $data['client_address']) : null)
            ->setDescription(!empty($data['description']) ? trim((string) $data['description']) : null)
            ->setNotes(!empty($data['notes']) ? trim((string) $data['notes']) : null)
            ->setRecipientAddress($workspace->getPayoutAddress())
            ->setAcceptedChains($data['accepted_chains'] ?? [$workspace->getPayoutChainId()])
            ->setAcceptedTokens($data['accepted_tokens'] ?? [$workspace->getPayoutToken()])
            ->setIssuedAt(new \DateTimeImmutable());

        if (!empty($data['due_date'])) {
            $invoice->setDueDate(new \DateTimeImmutable((string) $data['due_date']));
        }

        $this->applyLineItems($invoice, $data['line_items'] ?? []);

        $this->invoices->save($invoice);
        return $invoice;
    }

    /**
     * Apply the same DTO shape as create(), but in-place on an existing
     * Invoice. Only call this on drafts.
     */
    public function update(Invoice $invoice, array $data): Invoice
    {
        $invoice
            ->setCurrency($data['currency'] ?? $invoice->getCurrency())
            ->setClientName(trim((string) $data['client_name']))
            ->setClientEmail(!empty($data['client_email']) ? trim((string) $data['client_email']) : null)
            ->setClientAddress(!empty($data['client_address']) ? trim((string) $data['client_address']) : null)
            ->setDescription(!empty($data['description']) ? trim((string) $data['description']) : null)
            ->setNotes(!empty($data['notes']) ? trim((string) $data['notes']) : null)
            ->setAcceptedChains($data['accepted_chains'] ?? $invoice->getAcceptedChains())
            ->setAcceptedTokens($data['accepted_tokens'] ?? $invoice->getAcceptedTokens())
            ->touch();

        if (!empty($data['due_date'])) {
            $invoice->setDueDate(new \DateTimeImmutable((string) $data['due_date']));
        } else {
            $invoice->setDueDate(null);
        }

        foreach ($invoice->getLineItems()->toArray() as $existing) {
            $invoice->removeLineItem($existing);
        }
        $this->applyLineItems($invoice, $data['line_items'] ?? []);

        $this->invoices->save($invoice);
        return $invoice;
    }

    /** @param list<array{description:string, quantity?:string, unit_price_cents:int}> $items */
    private function applyLineItems(Invoice $invoice, array $items): void
    {
        $totalCents = 0;
        $position = 0;
        foreach ($items as $li) {
            $qty       = (string) ($li['quantity'] ?? '1.00');
            $unitCents = (int) $li['unit_price_cents'];
            $lineTotal = (int) round((float) bcmul($qty, (string) $unitCents, 6));

            $item = (new InvoiceLineItem())
                ->setDescription(trim((string) $li['description']))
                ->setQuantity($qty)
                ->setUnitPriceCents($unitCents)
                ->setTotalCents($lineTotal)
                ->setPosition($position++);
            $invoice->addLineItem($item);
            $totalCents += $lineTotal;
        }
        $invoice->setAmountCents($totalCents);
    }
}
