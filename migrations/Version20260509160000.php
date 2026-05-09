<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add token columns to users for email verification + password reset.
 *
 * The plaintext token is mailed to the user; the DB stores its SHA-256
 * hash so a leaked dump does not equal hijack-ready credentials. Tokens
 * have explicit expiry timestamps; controllers must check both presence
 * and expiry.
 */
final class Version20260509160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users: add email_verification + password_reset token columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE users
              ADD COLUMN email_verification_token       CHAR(64)  NULL AFTER email_verified_at,
              ADD COLUMN email_verification_expires_at  DATETIME  NULL AFTER email_verification_token,
              ADD COLUMN password_reset_token           CHAR(64)  NULL AFTER email_verification_expires_at,
              ADD COLUMN password_reset_expires_at      DATETIME  NULL AFTER password_reset_token,
              ADD COLUMN last_login_at                  DATETIME  NULL AFTER password_reset_expires_at,
              ADD INDEX idx_users_email_verification_token (email_verification_token),
              ADD INDEX idx_users_password_reset_token     (password_reset_token)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE users
              DROP INDEX idx_users_email_verification_token,
              DROP INDEX idx_users_password_reset_token,
              DROP COLUMN email_verification_token,
              DROP COLUMN email_verification_expires_at,
              DROP COLUMN password_reset_token,
              DROP COLUMN password_reset_expires_at,
              DROP COLUMN last_login_at
        SQL);
    }
}
