<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crypto-native billing: subscription Pro tier + accumulated per-invoice fees,
 * both paid in USDC to a platform-controlled wallet.
 *
 * Architecture (CLAUDE.md §13 — crypto-native variant, replacing the
 * earlier Stripe-fiat plan):
 *
 *   - Settlepay owns a wallet (PLATFORM_WALLET_ADDRESS env var). Same
 *     listener daemon that watches invoice recipients also watches
 *     this wallet for incoming USDC.
 *   - User clicks "Upgrade to Pro" → backend creates a `billing_intents`
 *     row (uuid, kind, amount) and redirects to /billing/pay/<uuid>.
 *   - On payment, listener detects → SubscriptionManager updates user.
 *
 * Tables added:
 *   - billing_intents: payment requests Settlepay generates
 *   - fee_payments:    on-chain Transfers received at the platform wallet
 *
 * Columns added to users:
 *   - plan_renews_at:    when the current Pro period ends (NULL = free or lifetime)
 *   - plan_canceled_at:  user clicked cancel (still has access until plan_renews_at)
 *   - fees_owed_cents:   accumulated unpaid % fees (1% Free, 0.5% Pro)
 */
final class Version20260511180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crypto-native billing: billing_intents + fee_payments tables + user plan columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE users
              ADD plan_renews_at DATETIME NULL COMMENT '(DC2Type:datetime_immutable)' AFTER plan,
              ADD plan_canceled_at DATETIME NULL COMMENT '(DC2Type:datetime_immutable)' AFTER plan_renews_at,
              ADD fees_owed_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER plan_canceled_at
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE billing_intents (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                uuid CHAR(36) NOT NULL,
                kind VARCHAR(40) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                amount_cents BIGINT UNSIGNED NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'USD',
                accepted_chains JSON NOT NULL,
                accepted_tokens JSON NOT NULL,
                recipient_address VARCHAR(64) NOT NULL,
                expected_payer_address VARCHAR(64) DEFAULT NULL,
                claimed_tx_hash VARCHAR(80) DEFAULT NULL,
                claimed_chain_id INT UNSIGNED DEFAULT NULL,
                claimed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                paid_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                paid_fee_payment_id BIGINT UNSIGNED DEFAULT NULL,
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_billing_intents_uuid (uuid),
                INDEX IDX_billing_intents_user_status (user_id, status),
                INDEX IDX_billing_intents_status_expires (status, expires_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE fee_payments (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                billing_intent_id BIGINT UNSIGNED DEFAULT NULL,
                chain_id INT UNSIGNED NOT NULL,
                tx_hash VARCHAR(80) NOT NULL,
                log_index INT UNSIGNED NOT NULL,
                block_number BIGINT UNSIGNED NOT NULL,
                block_timestamp DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                token_address VARCHAR(64) NOT NULL,
                token_symbol VARCHAR(20) NOT NULL,
                token_decimals TINYINT UNSIGNED NOT NULL,
                amount_raw VARCHAR(100) NOT NULL,
                amount_usd_cents BIGINT UNSIGNED DEFAULT NULL,
                payer_address VARCHAR(64) NOT NULL,
                recipient_address VARCHAR(64) NOT NULL,
                confirmations INT UNSIGNED NOT NULL DEFAULT 0,
                confirmed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_fee_payments_tx (chain_id, tx_hash, log_index),
                INDEX IDX_fee_payments_user (user_id),
                INDEX IDX_fee_payments_intent (billing_intent_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE billing_intents
                ADD CONSTRAINT FK_billing_intents_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE billing_intents
                ADD CONSTRAINT FK_billing_intents_payment
                FOREIGN KEY (paid_fee_payment_id) REFERENCES fee_payments (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE fee_payments
                ADD CONSTRAINT FK_fee_payments_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE fee_payments
                ADD CONSTRAINT FK_fee_payments_intent
                FOREIGN KEY (billing_intent_id) REFERENCES billing_intents (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_intents DROP FOREIGN KEY FK_billing_intents_payment');
        $this->addSql('ALTER TABLE fee_payments DROP FOREIGN KEY FK_fee_payments_intent');
        $this->addSql('ALTER TABLE fee_payments DROP FOREIGN KEY FK_fee_payments_user');
        $this->addSql('ALTER TABLE billing_intents DROP FOREIGN KEY FK_billing_intents_user');
        $this->addSql('DROP TABLE fee_payments');
        $this->addSql('DROP TABLE billing_intents');
        $this->addSql('ALTER TABLE users DROP fees_owed_cents, DROP plan_canceled_at, DROP plan_renews_at');
    }
}
