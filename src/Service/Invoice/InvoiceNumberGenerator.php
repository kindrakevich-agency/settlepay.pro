<?php

namespace App\Service\Invoice;

use App\Entity\Workspace;
use Doctrine\DBAL\Connection;

/**
 * Generates per-workspace, per-year, monotonically increasing invoice
 * numbers in the form `INV-2026-0042`.
 *
 *   - Sequence resets each calendar year, scoped to the workspace.
 *   - Concurrent calls are safe — we use SELECT … FOR UPDATE inside a
 *     transaction so two simultaneous create-invoice requests can't
 *     produce the same number.
 *   - Sequence numbers don't have to be perfectly contiguous (failed
 *     creates burn a number); they just have to be unique per workspace.
 */
final class InvoiceNumberGenerator
{
    public function __construct(private readonly Connection $db) {}

    public function next(Workspace $workspace): string
    {
        $year        = (int) (new \DateTimeImmutable())->format('Y');
        $workspaceId = (int) $workspace->getId();

        $this->db->beginTransaction();
        try {
            // Lock the highest current number for this workspace/year.
            $row = $this->db->fetchAssociative(
                'SELECT MAX(CAST(SUBSTRING_INDEX(number, \'-\', -1) AS UNSIGNED)) AS max_seq
                 FROM invoices
                 WHERE workspace_id = :wid AND number LIKE :pattern
                 FOR UPDATE',
                ['wid' => $workspaceId, 'pattern' => sprintf('INV-%d-%%', $year)],
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
