<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema — users, invoices, line items, payments, chain cursors, webhooks, audit log.
 * Mirrors the spec in CLAUDE.md §6.
 */
final class Version20260509120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: users, invoices, payments, listener cursors, webhooks, audit log.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              uuid CHAR(36) NOT NULL UNIQUE,
              email VARCHAR(255) NOT NULL UNIQUE,
              password_hash VARCHAR(255) NOT NULL,
              email_verified_at DATETIME NULL,
              display_name VARCHAR(120) NULL,
              business_name VARCHAR(180) NULL,
              business_address TEXT NULL,
              tax_id VARCHAR(60) NULL,
              default_currency CHAR(3) NOT NULL DEFAULT 'USD',
              default_locale VARCHAR(5) NOT NULL DEFAULT 'en',
              payout_address VARCHAR(64) NOT NULL,
              payout_chain_id INT UNSIGNED NOT NULL,
              payout_token VARCHAR(20) NOT NULL DEFAULT 'USDC',
              plan VARCHAR(20) NOT NULL DEFAULT 'free',
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              INDEX idx_users_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE invoices (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              uuid CHAR(36) NOT NULL UNIQUE,
              number VARCHAR(40) NOT NULL,
              user_id BIGINT UNSIGNED NOT NULL,
              status ENUM('draft','sent','viewed','paid','partially_paid','overdue','void','refunded') NOT NULL DEFAULT 'draft',
              amount_cents BIGINT UNSIGNED NOT NULL,
              currency CHAR(3) NOT NULL,
              client_name VARCHAR(180) NOT NULL,
              client_email VARCHAR(255) NULL,
              client_address TEXT NULL,
              description TEXT NULL,
              notes TEXT NULL,
              due_date DATE NULL,
              issued_at DATE NOT NULL,
              paid_at DATETIME NULL,
              viewed_at DATETIME NULL,
              accepted_chains JSON NOT NULL,
              accepted_tokens JSON NOT NULL,
              recipient_address VARCHAR(64) NOT NULL,
              metadata JSON NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              CONSTRAINT fk_invoices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
              INDEX idx_invoices_user_status (user_id, status),
              INDEX idx_invoices_status (status),
              INDEX idx_invoices_uuid (uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE invoice_line_items (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              invoice_id BIGINT UNSIGNED NOT NULL,
              description VARCHAR(500) NOT NULL,
              quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
              unit_price_cents BIGINT UNSIGNED NOT NULL,
              total_cents BIGINT UNSIGNED NOT NULL,
              position INT UNSIGNED NOT NULL DEFAULT 0,
              CONSTRAINT fk_line_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE payments (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              invoice_id BIGINT UNSIGNED NULL,
              chain_id INT UNSIGNED NOT NULL,
              tx_hash VARCHAR(80) NOT NULL,
              log_index INT UNSIGNED NOT NULL,
              block_number BIGINT UNSIGNED NOT NULL,
              block_timestamp DATETIME NOT NULL,
              token_address VARCHAR(64) NOT NULL,
              token_symbol VARCHAR(20) NOT NULL,
              token_decimals TINYINT UNSIGNED NOT NULL,
              amount_raw VARCHAR(100) NOT NULL,
              amount_usd_cents BIGINT UNSIGNED NULL,
              payer_address VARCHAR(64) NOT NULL,
              recipient_address VARCHAR(64) NOT NULL,
              confirmations INT UNSIGNED NOT NULL DEFAULT 0,
              confirmed_at DATETIME NULL,
              created_at DATETIME NOT NULL,
              UNIQUE KEY uniq_tx (chain_id, tx_hash, log_index),
              INDEX idx_payments_invoice (invoice_id),
              INDEX idx_payments_recipient (recipient_address),
              CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE chain_cursors (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              chain_id INT UNSIGNED NOT NULL UNIQUE,
              last_processed_block BIGINT UNSIGNED NOT NULL,
              updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE webhooks (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id BIGINT UNSIGNED NOT NULL,
              url VARCHAR(500) NOT NULL,
              secret VARCHAR(64) NOT NULL,
              events JSON NOT NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL,
              CONSTRAINT fk_webhooks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE audit_log (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id BIGINT UNSIGNED NULL,
              invoice_id BIGINT UNSIGNED NULL,
              event VARCHAR(100) NOT NULL,
              data JSON NULL,
              ip VARCHAR(64) NULL,
              user_agent VARCHAR(500) NULL,
              created_at DATETIME NOT NULL,
              INDEX idx_audit_user_event (user_id, event),
              INDEX idx_audit_invoice (invoice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS audit_log');
        $this->addSql('DROP TABLE IF EXISTS webhooks');
        $this->addSql('DROP TABLE IF EXISTS chain_cursors');
        $this->addSql('DROP TABLE IF EXISTS payments');
        $this->addSql('DROP TABLE IF EXISTS invoice_line_items');
        $this->addSql('DROP TABLE IF EXISTS invoices');
        $this->addSql('DROP TABLE IF EXISTS users');
    }
}
