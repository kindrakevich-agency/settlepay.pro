<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agency tier: bump default seat limit from 5 to 10.
 *
 * The pricing page launched at 5 seats; doubling to 10 makes the
 * tier sized for actual small studios (10 contributors covers most
 * 1–15-person agencies) without making the per-seat price unviable
 * — $49 / 10 = $4.90/seat, still healthy.
 *
 * Existing Agency workspaces (test data + any early signups) get
 * bumped to match the new pricing-page promise.
 */
final class Version20260511240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agency seat default: 5 → 10';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE workspaces SET seat_limit = 10 WHERE plan = 'agency'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE workspaces SET seat_limit = 5 WHERE plan = 'agency' AND seat_limit = 10");
    }
}
