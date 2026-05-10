<?php

namespace App\Service\Invoice;

use App\Entity\Invoice;
use App\Entity\InvoiceLineItem;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Repository\InvoiceRepository;

/**
 * Builds Invoice entities from controller-supplied DTOs.
 *
 * Centralizes:
 *   - Number generation (delegates to InvoiceNumberGenerator)
 *   - amount_cents = Σ line_item totals (no float math)
 *   - recipient_address snapshot from user.payout_address
 *   - accepted_chains / accepted_tokens default to user defaults
 *
 * Controllers pass plain arrays so this class stays usable from CLI seed
 * commands too.
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
    public function create(User $user, array $data): Invoice
    {
        $invoice = new Invoice();
        $invoice
            ->setUser($user)
            ->setNumber($this->numbers->next($user))
            ->setStatus(InvoiceStatus::Draft)
            ->setCurrency($data['currency'] ?? $user->getDefaultCurrency())
            ->setClientName(trim((string) $data['client_name']))
            ->setClientEmail(!empty($data['client_email']) ? trim((string) $data['client_email']) : null)
            ->setClientAddress(!empty($data['client_address']) ? trim((string) $data['client_address']) : null)
            ->setDescription(!empty($data['description']) ? trim((string) $data['description']) : null)
            ->setNotes(!empty($data['notes']) ? trim((string) $data['notes']) : null)
            ->setRecipientAddress($user->getPayoutAddress())
            ->setAcceptedChains($data['accepted_chains'] ?? [(int) $user->getPayoutChainId()])
            ->setAcceptedTokens($data['accepted_tokens'] ?? [$user->getPayoutToken()])
            ->setIssuedAt(new \DateTimeImmutable());

        if (!empty($data['due_date'])) {
            $invoice->setDueDate(new \DateTimeImmutable((string) $data['due_date']));
        }

        $totalCents = 0;
        $position = 0;
        foreach ($data['line_items'] ?? [] as $li) {
            $qty       = (string) ($li['quantity'] ?? '1.00');
            $unitCents = (int) $li['unit_price_cents'];
            // total = round(quantity * unit_cents). Quantity is decimal,
            // unit price is integer cents — multiply through bcmath then
            // round to whole cents to keep the rule "money is integer cents".
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

        $this->invoices->save($invoice);
        return $invoice;
    }
}
