<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017102622 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game.game DROP room_cursed_player_ids');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game.game ADD room_cursed_player_ids JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('COMMENT ON COLUMN game.game.room_cursed_player_ids IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidArrayJsonType)\'');
    }
}
