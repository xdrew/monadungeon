<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261014183510 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Curse of Karak: curse token on game, cursed flag on player';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game.game ADD cursed_player_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE game.game ADD curse_choice_pending_by UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE game.game ADD curse_choice_turn_id UUID DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN game.game.cursed_player_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN game.game.curse_choice_pending_by IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN game.game.curse_choice_turn_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('ALTER TABLE player.player ADD cursed BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game.game DROP cursed_player_id');
        $this->addSql('ALTER TABLE game.game DROP curse_choice_pending_by');
        $this->addSql('ALTER TABLE game.game DROP curse_choice_turn_id');
        $this->addSql('ALTER TABLE player.player DROP cursed');
    }
}
