<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015073252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Karak: Regent — expansion flag, Cursed Room curses, Battle Arena duel';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA duel');
        $this->addSql('CREATE TABLE duel.duel (duel_id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, phase VARCHAR(255) NOT NULL, begun BOOLEAN DEFAULT false NOT NULL, challenger_is_ai BOOLEAN NOT NULL, opponent_is_ai BOOLEAN NOT NULL, first_step BOOLEAN NOT NULL, challenger_total INT DEFAULT NULL, opponent_total INT DEFAULT NULL, challenger_details jsonb, opponent_details jsonb, result VARCHAR(255) DEFAULT NULL, loser_id UUID DEFAULT NULL, reincarnated_to VARCHAR(255) DEFAULT NULL, loot jsonb, loot_candidates jsonb, game_id UUID NOT NULL, turn_id UUID NOT NULL, arena_position VARCHAR(255) NOT NULL, challenger_id UUID NOT NULL, opponent_id UUID NOT NULL, challenger_battle_id UUID NOT NULL, opponent_battle_id UUID NOT NULL, PRIMARY KEY(duel_id))');
        $this->addSql('COMMENT ON COLUMN duel.duel.duel_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN duel.duel.loser_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN duel.duel.game_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN duel.duel.turn_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN duel.duel.challenger_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN duel.duel.opponent_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN duel.duel.challenger_battle_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN duel.duel.opponent_battle_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('ALTER TABLE battle.battle ADD duel_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE battle.battle ADD duel_role VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE battle.battle ADD duel_opponent_id UUID DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN battle.battle.duel_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN battle.battle.duel_opponent_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('ALTER TABLE field.field ADD room_event jsonb');
        $this->addSql('ALTER TABLE field.field ADD activated_arenas JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE field.field ADD pending_arena jsonb');
        $this->addSql('ALTER TABLE field.field ADD duel_info jsonb');
        $this->addSql('COMMENT ON COLUMN field.field.activated_arenas IS \'(DC2Type:App\\Game\\Field\\DoctrineDBAL\\FieldPlaceArrayJsonType)\'');
        $this->addSql('ALTER TABLE game.game ADD regent BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE game.game ADD room_cursed_player_ids JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('COMMENT ON COLUMN game.game.room_cursed_player_ids IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidArrayJsonType)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE duel.duel');
        $this->addSql('DROP SCHEMA duel');
        $this->addSql('ALTER TABLE battle.battle DROP duel_id');
        $this->addSql('ALTER TABLE battle.battle DROP duel_role');
        $this->addSql('ALTER TABLE battle.battle DROP duel_opponent_id');
        $this->addSql('ALTER TABLE field.field DROP room_event');
        $this->addSql('ALTER TABLE field.field DROP activated_arenas');
        $this->addSql('ALTER TABLE field.field DROP pending_arena');
        $this->addSql('ALTER TABLE field.field DROP duel_info');
        $this->addSql('ALTER TABLE game.game DROP regent');
        $this->addSql('ALTER TABLE game.game DROP room_cursed_player_ids');
    }
}
