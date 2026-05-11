<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Agency tier v1 — workspace as the unit of "the business":
 *
 *   - workspaces:           shared pool of invoices/billing/branding owned by N users
 *   - workspace_members:    pivot, role ∈ {owner, member}
 *   - workspace_invitations: pending email invites with signed tokens
 *
 * The migration auto-creates one solo workspace per existing user
 * (role=owner) so the new world is a strict superset of the old one.
 * Workspace plan/billing/branding fields are seeded from the user's
 * own values so the listener + UI keep working unchanged.
 *
 * `workspace_id` columns are added to invoices/api_tokens/webhooks/
 * billing_intents nullable in this migration; a later migration will
 * make them NOT NULL after the controllers route through the
 * workspace context.
 */
final class Version20260511220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agency tier v1: workspaces + workspace_members + workspace_invitations + backfill';
    }

    public function up(Schema $schema): void
    {
        // Migration is idempotent on retry: a previous failed attempt may
        // have left some tables created. IF NOT EXISTS / IF EXISTS guards
        // let the migration recover cleanly without manual SQL cleanup.
        $this->addSql('DROP TABLE IF EXISTS workspace_invitations');
        $this->addSql('DROP TABLE IF EXISTS workspace_members');
        $this->addSql('DROP TABLE IF EXISTS workspaces');

        // ─── workspaces ───────────────────────────────────────────────
        $this->addSql(<<<SQL
            CREATE TABLE workspaces (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                uuid CHAR(36) NOT NULL UNIQUE,
                name VARCHAR(180) NOT NULL,
                plan VARCHAR(20) NOT NULL DEFAULT 'free',
                plan_renews_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                plan_canceled_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                fees_owed_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
                business_name VARCHAR(180) DEFAULT NULL,
                business_address TEXT DEFAULT NULL,
                tax_id VARCHAR(60) DEFAULT NULL,
                default_currency CHAR(3) NOT NULL DEFAULT 'USD',
                default_locale VARCHAR(5) NOT NULL DEFAULT 'en',
                payout_address VARCHAR(64) NOT NULL,
                payout_chain_id INT UNSIGNED NOT NULL,
                payout_token VARCHAR(20) NOT NULL DEFAULT 'USDC',
                brand_logo_path VARCHAR(255) DEFAULT NULL,
                brand_color VARCHAR(7) DEFAULT NULL,
                seat_limit INT UNSIGNED NOT NULL DEFAULT 1,
                owner_user_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY(id),
                INDEX IDX_workspaces_owner (owner_user_id),
                INDEX IDX_workspaces_plan (plan)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql('ALTER TABLE workspaces ADD CONSTRAINT FK_workspaces_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE RESTRICT');

        // ─── workspace_members ────────────────────────────────────────
        $this->addSql(<<<SQL
            CREATE TABLE workspace_members (
                workspace_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                role VARCHAR(16) NOT NULL DEFAULT 'member',
                joined_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY(workspace_id, user_id),
                INDEX IDX_wm_user (user_id),
                INDEX IDX_wm_role (role)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql('ALTER TABLE workspace_members ADD CONSTRAINT FK_wm_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workspace_members ADD CONSTRAINT FK_wm_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        // ─── workspace_invitations ────────────────────────────────────
        $this->addSql(<<<SQL
            CREATE TABLE workspace_invitations (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                email VARCHAR(255) NOT NULL,
                role VARCHAR(16) NOT NULL DEFAULT 'member',
                token VARCHAR(64) NOT NULL UNIQUE,
                invited_by_user_id BIGINT UNSIGNED NOT NULL,
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                accepted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY(id),
                INDEX IDX_wi_workspace (workspace_id),
                INDEX IDX_wi_email (email)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql('ALTER TABLE workspace_invitations ADD CONSTRAINT FK_wi_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workspace_invitations ADD CONSTRAINT FK_wi_invited_by FOREIGN KEY (invited_by_user_id) REFERENCES users (id) ON DELETE RESTRICT');

        // ─── workspace_id columns on existing per-user tables ────────
        // Nullable in this migration so backfill can populate them; a
        // later migration tightens them to NOT NULL once writes route
        // through the workspace context.
        // ALTER TABLE doesn't support IF NOT EXISTS portably; do a runtime
        // check via information_schema so retries after a partial failure
        // don't crash on "column already exists".
        $this->addWorkspaceIdColumnIfMissing('invoices',        'IDX_inv_workspace');
        $this->addWorkspaceIdColumnIfMissing('api_tokens',      'IDX_apit_workspace');
        $this->addWorkspaceIdColumnIfMissing('webhooks',        'IDX_wh_workspace');
        $this->addWorkspaceIdColumnIfMissing('billing_intents', 'IDX_bi_workspace');
    }

    private function addWorkspaceIdColumnIfMissing(string $table, string $indexName): void
    {
        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'workspace_id']
        );
        if ($exists === 0) {
            $this->connection->executeStatement(sprintf(
                'ALTER TABLE %s ADD COLUMN workspace_id BIGINT UNSIGNED DEFAULT NULL, ADD INDEX %s (workspace_id)',
                $table, $indexName
            ));
        }
    }

    /**
     * postUp runs AFTER all queued up() SQL has been executed, so
     * `workspaces` definitely exists by the time we INSERT into it.
     */
    public function postUp(Schema $schema): void
    {
        // ─── Backfill: one workspace per user, that user as owner ───
        // We can't use the Workspace entity from inside a migration, so
        // raw SQL. The UUID is generated per-row in PHP (MariaDB has
        // UUID() but it's v1 — we want v7 to match the rest of the
        // codebase).
        $userRows = $this->connection->fetchAllAssociative(<<<SQL
            SELECT id, business_name, business_address, tax_id,
                   default_currency, default_locale,
                   payout_address, payout_chain_id, payout_token,
                   brand_logo_path, brand_color,
                   plan, plan_renews_at, plan_canceled_at, fees_owed_cents,
                   created_at, updated_at
            FROM users
        SQL);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($userRows as $u) {
            $uuid = Uuid::v7()->toRfc4122();
            $this->connection->insert('workspaces', [
                'uuid'             => $uuid,
                'name'             => $u['business_name'] ?: 'Workspace',
                'plan'             => $u['plan'] ?: 'free',
                'plan_renews_at'   => $u['plan_renews_at'],
                'plan_canceled_at' => $u['plan_canceled_at'],
                'fees_owed_cents'  => $u['fees_owed_cents'] ?: 0,
                'business_name'    => $u['business_name'],
                'business_address' => $u['business_address'],
                'tax_id'           => $u['tax_id'],
                'default_currency' => $u['default_currency'] ?: 'USD',
                'default_locale'   => $u['default_locale'] ?: 'en',
                'payout_address'   => $u['payout_address'] ?: '0x0000000000000000000000000000000000000000',
                'payout_chain_id'  => $u['payout_chain_id'] ?: 8453,
                'payout_token'     => $u['payout_token'] ?: 'USDC',
                'brand_logo_path'  => $u['brand_logo_path'],
                'brand_color'      => $u['brand_color'],
                'seat_limit'       => ($u['plan'] === 'agency') ? 5 : 1,
                'owner_user_id'    => $u['id'],
                'created_at'       => $u['created_at'] ?: $now,
                'updated_at'       => $u['updated_at'] ?: $now,
            ]);
            $workspaceId = (int) $this->connection->lastInsertId();

            $this->connection->insert('workspace_members', [
                'workspace_id' => $workspaceId,
                'user_id'      => $u['id'],
                'role'         => 'owner',
                'joined_at'    => $u['created_at'] ?: $now,
            ]);

            // Repoint owned rows to the new workspace.
            $this->connection->executeStatement('UPDATE invoices        SET workspace_id = ? WHERE user_id = ?', [$workspaceId, $u['id']]);
            $this->connection->executeStatement('UPDATE api_tokens      SET workspace_id = ? WHERE user_id = ?', [$workspaceId, $u['id']]);
            $this->connection->executeStatement('UPDATE webhooks        SET workspace_id = ? WHERE user_id = ?', [$workspaceId, $u['id']]);
            $this->connection->executeStatement('UPDATE billing_intents SET workspace_id = ? WHERE user_id = ?', [$workspaceId, $u['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_intents  DROP INDEX IDX_bi_workspace, DROP COLUMN workspace_id');
        $this->addSql('ALTER TABLE webhooks         DROP INDEX IDX_wh_workspace, DROP COLUMN workspace_id');
        $this->addSql('ALTER TABLE api_tokens       DROP INDEX IDX_apit_workspace, DROP COLUMN workspace_id');
        $this->addSql('ALTER TABLE invoices         DROP INDEX IDX_inv_workspace, DROP COLUMN workspace_id');

        $this->addSql('ALTER TABLE workspace_invitations DROP FOREIGN KEY FK_wi_invited_by');
        $this->addSql('ALTER TABLE workspace_invitations DROP FOREIGN KEY FK_wi_workspace');
        $this->addSql('DROP TABLE workspace_invitations');

        $this->addSql('ALTER TABLE workspace_members DROP FOREIGN KEY FK_wm_user');
        $this->addSql('ALTER TABLE workspace_members DROP FOREIGN KEY FK_wm_workspace');
        $this->addSql('DROP TABLE workspace_members');

        $this->addSql('ALTER TABLE workspaces DROP FOREIGN KEY FK_workspaces_owner');
        $this->addSql('DROP TABLE workspaces');
    }

    public function isTransactional(): bool
    {
        // MariaDB silently commits on DDL — wrapping the backfill in an
        // outer transaction is pointless. Plus the row-by-row loop fits
        // better outside a transaction so a partial failure is easier
        // to inspect.
        return false;
    }
}
