<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Personal access tokens for the Settlepay API.
 *
 * Pro-tier feature — Free users can't mint tokens (controller-level
 * gate). Tokens look like `sk_pro_<43 base64url chars>`. Only the
 * Argon2id hash is persisted; the plaintext is shown ONCE at creation
 * time and can never be retrieved again (force user to copy it
 * immediately or revoke + remint).
 */
final class Version20260511190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'api_tokens table — personal access tokens for the Pro-tier API';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE api_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(80) NOT NULL,
                token_prefix VARCHAR(16) NOT NULL,
                token_hash VARCHAR(255) NOT NULL,
                scopes JSON NOT NULL,
                last_used_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_api_tokens_user (user_id),
                INDEX IDX_api_tokens_prefix (token_prefix),
                INDEX IDX_api_tokens_revoked (revoked_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE api_tokens
                ADD CONSTRAINT FK_api_tokens_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens DROP FOREIGN KEY FK_api_tokens_user');
        $this->addSql('DROP TABLE api_tokens');
    }
}
