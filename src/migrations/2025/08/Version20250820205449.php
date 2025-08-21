<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250820205449 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA bag');
        $this->addSql('CREATE SCHEMA battle');
        $this->addSql('CREATE SCHEMA deck');
        $this->addSql('CREATE SCHEMA field');
        $this->addSql('CREATE SCHEMA game');
        $this->addSql('CREATE SCHEMA game_turn');
        $this->addSql('CREATE SCHEMA movement');
        $this->addSql('CREATE SCHEMA player');
        $this->addSql('CREATE TABLE bag.bag (game_id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, items jsonb, PRIMARY KEY(game_id))');
        $this->addSql('COMMENT ON COLUMN bag.bag.game_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN bag.bag.items IS \'(DC2Type:App\\Game\\Item\\DoctrineDBAL\\ItemArrayJsonType)\'');
        $this->addSql('CREATE TABLE battle.battle (battle_id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, dice_results jsonb, total_damage INT NOT NULL, used_items jsonb, from_position VARCHAR(255) DEFAULT NULL, to_position VARCHAR(255) DEFAULT NULL, monster_data jsonb, battle_completed BOOLEAN NOT NULL, game_id UUID NOT NULL, player_id UUID NOT NULL, turn_id UUID NOT NULL, guard_hp INT NOT NULL, PRIMARY KEY(battle_id))');
        $this->addSql('COMMENT ON COLUMN battle.battle.battle_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN battle.battle.used_items IS \'(DC2Type:App\\Game\\Item\\DoctrineDBAL\\ItemArrayJsonType)\'');
        $this->addSql('COMMENT ON COLUMN battle.battle.game_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN battle.battle.player_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN battle.battle.turn_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('CREATE TABLE deck.deck (game_id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, tiles_total_count INT NOT NULL, room_count INT NOT NULL, tiles_remaining_count INT NOT NULL, tiles jsonb, PRIMARY KEY(game_id))');
        $this->addSql('COMMENT ON COLUMN deck.deck.game_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN deck.deck.tiles IS \'(DC2Type:App\\Game\\Deck\\DoctrineDBAL\\DeckTileArrayJsonType)\'');
        $this->addSql('CREATE TABLE field.field (game_id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, tiles jsonb, tile_orientations jsonb, room_field_places JSON NOT NULL, available_field_places JSON NOT NULL, available_field_places_orientation jsonb, items jsonb, test_dice_rolls jsonb, transitions jsonb, last_battle_info jsonb, consumed_item_ids jsonb, teleportation_gate_positions JSON NOT NULL, healing_fountain_positions JSON NOT NULL, PRIMARY KEY(game_id))');
        $this->addSql('COMMENT ON COLUMN field.field.game_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN field.field.room_field_places IS \'(DC2Type:App\\Game\\Field\\DoctrineDBAL\\FieldPlaceArrayJsonType)\'');
        $this->addSql('COMMENT ON COLUMN field.field.available_field_places IS \'(DC2Type:App\\Game\\Field\\DoctrineDBAL\\FieldPlaceArrayJsonType)\'');
        $this->addSql('COMMENT ON COLUMN field.field.available_field_places_orientation IS \'(DC2Type:App\\Game\\Field\\DoctrineDBAL\\TileOrientationMapType)\'');
        $this->addSql('COMMENT ON COLUMN field.field.items IS \'(DC2Type:App\\Game\\Field\\DoctrineDBAL\\ItemMapType)\'');
        $this->addSql('COMMENT ON COLUMN field.field.teleportation_gate_positions IS \'(DC2Type:App\\Game\\Field\\DoctrineDBAL\\FieldPlaceArrayJsonType)\'');
        $this->addSql('COMMENT ON COLUMN field.field.healing_fountain_positions IS \'(DC2Type:App\\Game\\Field\\DoctrineDBAL\\FieldPlaceArrayJsonType)\'');
        $this->addSql('CREATE TABLE game.game (game_id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, status VARCHAR(255) NOT NULL, players JSON NOT NULL, current_player_id UUID DEFAULT NULL, current_turn_number INT NOT NULL, current_turn_id UUID DEFAULT NULL, leaderboard jsonb, winner_id UUID DEFAULT NULL, PRIMARY KEY(game_id))');
        $this->addSql('COMMENT ON COLUMN game.game.game_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN game.game.players IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidArrayJsonType)\'');
        $this->addSql('COMMENT ON COLUMN game.game.current_player_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN game.game.current_turn_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN game.game.winner_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('CREATE TABLE game_turn.game_turn (turn_id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, actions jsonb, performed_actions_count INT NOT NULL, start_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, end_time TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, game_id UUID NOT NULL, player_id UUID NOT NULL, turn_number INT NOT NULL, PRIMARY KEY(turn_id))');
        $this->addSql('COMMENT ON COLUMN game_turn.game_turn.turn_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN game_turn.game_turn.start_time IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN game_turn.game_turn.end_time IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN game_turn.game_turn.game_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN game_turn.game_turn.player_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('CREATE TABLE movement.movement (game_id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, player_positions jsonb, transitions jsonb, teleportation_connections jsonb, movement_restrictions jsonb, has_moved_after_battle jsonb, PRIMARY KEY(game_id))');
        $this->addSql('COMMENT ON COLUMN movement.movement.game_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN movement.movement.player_positions IS \'(DC2Type:App\\Game\\Movement\\DoctrineDBAL\\PlayerPositionMapType)\'');
        $this->addSql('CREATE TABLE player.player (player_id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, character_id UUID DEFAULT NULL, ready BOOLEAN NOT NULL, external_id VARCHAR(255) DEFAULT NULL, username VARCHAR(255) DEFAULT NULL, wallet_address VARCHAR(255) DEFAULT NULL, inventory jsonb, game_id UUID NOT NULL, hp INT NOT NULL, PRIMARY KEY(player_id))');
        $this->addSql('COMMENT ON COLUMN player.player.player_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN player.player.character_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN player.player.inventory IS \'(DC2Type:App\\Game\\Bag\\DoctrineDBAL\\InventoryJsonType)\'');
        $this->addSql('COMMENT ON COLUMN player.player.game_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('CREATE TABLE field.tile (tile_id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, players JSON NOT NULL, orientation JSON NOT NULL, room BOOLEAN NOT NULL, features JSON DEFAULT \'[]\' NOT NULL, PRIMARY KEY(tile_id))');
        $this->addSql('COMMENT ON COLUMN field.tile.tile_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN field.tile.players IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidArrayJsonType)\'');
        $this->addSql('COMMENT ON COLUMN field.tile.orientation IS \'(DC2Type:App\\Game\\Field\\DoctrineDBAL\\TileOrientationType)\'');
        $this->addSql('COMMENT ON COLUMN field.tile.features IS \'(DC2Type:App\\Game\\Field\\DoctrineDBAL\\TileFeatureArrayType)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE bag.bag');
        $this->addSql('DROP TABLE battle.battle');
        $this->addSql('DROP TABLE deck.deck');
        $this->addSql('DROP TABLE field.field');
        $this->addSql('DROP TABLE game.game');
        $this->addSql('DROP TABLE game_turn.game_turn');
        $this->addSql('DROP TABLE movement.movement');
        $this->addSql('DROP TABLE player.player');
        $this->addSql('DROP TABLE field.tile');
    }
}
