<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250827165636 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE field.field ALTER unplaced_tile TYPE JSON');
        $this->addSql('COMMENT ON COLUMN field.field.unplaced_tile IS \'(DC2Type:App\\Game\\Field\\DoctrineDBAL\\UnplacedTileType)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE field.field ALTER unplaced_tile TYPE JSONB');
        $this->addSql('COMMENT ON COLUMN field.field.unplaced_tile IS NULL');
    }
}
