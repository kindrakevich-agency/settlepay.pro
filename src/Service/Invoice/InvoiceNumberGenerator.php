<?php

namespace App\Service\Invoice;

use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Generates per-user, per-year, monotonically increasing invoice numbers
 * in the form `INV-2026-0042`.
 *
 *   - Sequence resets each calendar year, scoped to the user.
 *   - Concurrent calls are safe — we use SELECT … FOR UPDATE inside a
 *     transaction so two simultaneous create-invoice requests can't
 *     produce the same number.
 *   - Sequence numbers don't have to be perfectly contiguous (failed
 *     creates burn a number); they just have to be unique per user.
 */
final class InvoiceNumberGenerator
{
    public function __construct(private readonly Connection $db) {}

    public function next(User $user): string
    {
        $year   = (int) (new \DateTimeImmutable())->format('Y');
        $userId = (int) $user->getId();

        $this->db->beginTransaction();
        try {
            // Lock the highest current number for this user/year.
            $row = $this->db->fetchAssociative(
                'SELECT MAX(CAST(SUBSTRING_INDEX(number, \'-\', -1) AS UNSIGNED)) AS max_seq
                 FROM invoices
                 WHERE user_id = :uid AND number LIKE :pattern
                 FOR UPDATE',
                ['uid' => $userId, 'pattern' => sprintf('INV-%d-%%', $year)],
            );
            $next = ((int) ($row['max_seq'] ?? 0)) + 1;
            $number = sprintf('INV-%d-%04d', $year, $next);
            $this->db->commit();
            return $number;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
