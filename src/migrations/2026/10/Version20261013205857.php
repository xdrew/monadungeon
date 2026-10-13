<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261013205857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hero skills: player hero + reincarnation state, battle Double Attack reroll state';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE battle.battle ADD reroll_used BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE battle.battle ADD first_dice_results jsonb');
        $this->addSql('ALTER TABLE player.player ADD hero VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE player.player ADD awaiting_reincarnation BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player.player DROP hero');
        $this->addSql('ALTER TABLE player.player DROP awaiting_reincarnation');
        $this->addSql('ALTER TABLE battle.battle DROP reroll_used');
        $this->addSql('ALTER TABLE battle.battle DROP first_dice_results');
    }
}
