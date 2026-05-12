<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.google_sub for Google Identity Services sign-in.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD COLUMN google_sub VARCHAR(64) NULL DEFAULT NULL AFTER email_verification_expires_at");
        $this->addSql("ALTER TABLE users ADD UNIQUE INDEX UNIQ_users_google_sub (google_sub)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users DROP INDEX UNIQ_users_google_sub");
        $this->addSql("ALTER TABLE users DROP COLUMN google_sub");
    }
}
