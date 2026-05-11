<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Outgoing HTTP webhooks: users register URLs that receive HMAC-signed
 * POST callbacks on invoice/payment events.
 *
 * Pro-only feature (controller-level gate). Each webhook stores:
 *   - url             destination
 *   - signing_secret  64-char base64url, used as HMAC-SHA256 key
 *   - events          JSON array of event names the user subscribed to
 *   - is_active       allows pausing without deletion
 *
 * Delivery state lives in `webhook_deliveries` so support can replay /
 * debug failures.
 */
final class Version20260511200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'webhooks + webhook_deliveries — Pro-tier outgoing event callbacks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE webhooks (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                url VARCHAR(500) NOT NULL,
                signing_secret VARCHAR(128) NOT NULL,
                events JSON NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_success_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                last_failure_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                last_failure_reason VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_webhooks_user (user_id),
                INDEX IDX_webhooks_active (is_active),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE webhooks
                ADD CONSTRAINT FK_webhooks_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE webhook_deliveries (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                webhook_id BIGINT UNSIGNED NOT NULL,
                event VARCHAR(60) NOT NULL,
                payload_json LONGTEXT NOT NULL,
                attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_status_code INT DEFAULT NULL,
                last_response_body TEXT DEFAULT NULL,
                last_error VARCHAR(255) DEFAULT NULL,
                delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_webhook_deliveries_webhook (webhook_id),
                INDEX IDX_webhook_deliveries_event (event),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE webhook_deliveries
                ADD CONSTRAINT FK_webhook_deliveries_webhook
                FOREIGN KEY (webhook_id) REFERENCES webhooks (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webhook_deliveries DROP FOREIGN KEY FK_webhook_deliveries_webhook');
        $this->addSql('DROP TABLE webhook_deliveries');
        $this->addSql('ALTER TABLE webhooks DROP FOREIGN KEY FK_webhooks_user');
        $this->addSql('DROP TABLE webhooks');
    }
}
