<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2 finalization — tighten `workspace_id` to NOT NULL on every
 * workspace-owned table now that all writes (controllers, factory,
 * billing payments matcher) set the workspace.
 *
 * Also add the FK constraints we deferred in Version20260511220000 —
 * we couldn't add them then because workspaces didn't exist yet at
 * the time addColumn ran.
 *
 * Defensive: anything still NULL after the Phase 2 rollout was a row
 * that pre-dated Agency v1 AND was untouched since. We backfill from
 * the user's owned workspace (every user has one) before tightening.
 */
final class Version20260511230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2 finalization: workspace_id NOT NULL + FKs on invoices/api_tokens/webhooks/billing_intents';
    }

    public function up(Schema $schema): void
    {
        // ─── Backfill any straggler rows from the user's owned workspace ───
        // Cheap: invoices/api_tokens/webhooks/billing_intents.user_id → users.id
        // → workspaces.owner_user_id. JOIN to find each user's solo workspace.
        $sql = <<<'SQL'
            UPDATE %s t
            JOIN workspaces w ON w.owner_user_id = t.user_id
            SET t.workspace_id = w.id
            WHERE t.workspace_id IS NULL
        SQL;
        foreach (['invoices', 'api_tokens', 'webhooks', 'billing_intents'] as $table) {
            $this->connection->executeStatement(sprintf($sql, $table));
        }
    }

    public function postUp(Schema $schema): void
    {
        // NOT NULL + FKs deferred until after backfill committed.
        $this->connection->executeStatement(
            'ALTER TABLE invoices MODIFY workspace_id BIGINT UNSIGNED NOT NULL'
        );
        $this->connection->executeStatement(
            'ALTER TABLE invoices ADD CONSTRAINT FK_invoices_workspace
                FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE RESTRICT'
        );

        $this->connection->executeStatement(
            'ALTER TABLE api_tokens MODIFY workspace_id BIGINT UNSIGNED NOT NULL'
        );
        $this->connection->executeStatement(
            'ALTER TABLE api_tokens ADD CONSTRAINT FK_api_tokens_workspace
                FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE'
        );

        $this->connection->executeStatement(
            'ALTER TABLE webhooks MODIFY workspace_id BIGINT UNSIGNED NOT NULL'
        );
        $this->connection->executeStatement(
            'ALTER TABLE webhooks ADD CONSTRAINT FK_webhooks_workspace
                FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE'
        );

        $this->connection->executeStatement(
            'ALTER TABLE billing_intents MODIFY workspace_id BIGINT UNSIGNED NOT NULL'
        );
        $this->connection->executeStatement(
            'ALTER TABLE billing_intents ADD CONSTRAINT FK_billing_intents_workspace
                FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_intents DROP FOREIGN KEY FK_billing_intents_workspace');
        $this->addSql('ALTER TABLE billing_intents MODIFY workspace_id BIGINT UNSIGNED DEFAULT NULL');

        $this->addSql('ALTER TABLE webhooks DROP FOREIGN KEY FK_webhooks_workspace');
        $this->addSql('ALTER TABLE webhooks MODIFY workspace_id BIGINT UNSIGNED DEFAULT NULL');

        $this->addSql('ALTER TABLE api_tokens DROP FOREIGN KEY FK_api_tokens_workspace');
        $this->addSql('ALTER TABLE api_tokens MODIFY workspace_id BIGINT UNSIGNED DEFAULT NULL');

        $this->addSql('ALTER TABLE invoices DROP FOREIGN KEY FK_invoices_workspace');
        $this->addSql('ALTER TABLE invoices MODIFY workspace_id BIGINT UNSIGNED DEFAULT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
