<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Custom branding columns for Pro users: a logo path + accent color.
 * Both nullable — free users simply leave them empty and we render
 * the default Settlepay mark.
 */
final class Version20260511210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users.brand_logo_path + users.brand_color — Pro custom branding';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE users
                ADD COLUMN brand_logo_path VARCHAR(255) DEFAULT NULL,
                ADD COLUMN brand_color VARCHAR(7) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN brand_logo_path, DROP COLUMN brand_color');
    }
}
