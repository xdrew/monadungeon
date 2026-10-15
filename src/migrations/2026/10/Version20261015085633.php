<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015085633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Karak: Regent heroes — Berserk, Bear Attack, Lightning Attack retreat, Sprint, heal limit';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE battle.battle ADD berserk_bonus INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE battle.battle ADD ones_as_sixes INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE battle.battle ADD lightning BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE field.field ADD pending_retreat jsonb');
        $this->addSql('ALTER TABLE game_turn.game_turn ADD sprint BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE player.player ADD heal_limit INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE battle.battle DROP berserk_bonus');
        $this->addSql('ALTER TABLE battle.battle DROP ones_as_sixes');
        $this->addSql('ALTER TABLE battle.battle DROP lightning');
        $this->addSql('ALTER TABLE game_turn.game_turn DROP sprint');
        $this->addSql('ALTER TABLE player.player DROP heal_limit');
        $this->addSql('ALTER TABLE field.field DROP pending_retreat');
    }
}
