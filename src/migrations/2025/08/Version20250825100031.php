<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250825100031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bag.bag ALTER items SET NOT NULL');
        $this->addSql('ALTER TABLE battle.battle ALTER dice_results SET NOT NULL');
        $this->addSql('ALTER TABLE deck.deck ALTER tiles SET NOT NULL');
        $this->addSql('ALTER TABLE field.field ALTER tiles SET NOT NULL');
        $this->addSql('ALTER TABLE field.field ALTER tile_orientations SET NOT NULL');
        $this->addSql('ALTER TABLE field.field ALTER available_field_places_orientation SET NOT NULL');
        $this->addSql('ALTER TABLE field.field ALTER items SET NOT NULL');
        $this->addSql('ALTER TABLE field.field ALTER transitions SET NOT NULL');
        $this->addSql('ALTER TABLE field.field ALTER last_battle_info SET NOT NULL');
        $this->addSql('ALTER TABLE field.field ALTER consumed_item_ids SET NOT NULL');
        $this->addSql('ALTER TABLE game.game ALTER leaderboard SET NOT NULL');
        $this->addSql('ALTER TABLE game_turn.game_turn ALTER actions SET NOT NULL');
        $this->addSql('ALTER TABLE movement.movement ALTER player_positions SET NOT NULL');
        $this->addSql('ALTER TABLE movement.movement ALTER transitions SET NOT NULL');
        $this->addSql('ALTER TABLE movement.movement ALTER teleportation_connections SET NOT NULL');
        $this->addSql('ALTER TABLE movement.movement ALTER movement_restrictions SET NOT NULL');
        $this->addSql('ALTER TABLE movement.movement ALTER has_moved_after_battle SET NOT NULL');
        $this->addSql('ALTER TABLE player.player ADD is_ai BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE player.player ALTER inventory SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE field.field ALTER tiles DROP NOT NULL');
        $this->addSql('ALTER TABLE field.field ALTER tile_orientations DROP NOT NULL');
        $this->addSql('ALTER TABLE field.field ALTER available_field_places_orientation DROP NOT NULL');
        $this->addSql('ALTER TABLE field.field ALTER items DROP NOT NULL');
        $this->addSql('ALTER TABLE field.field ALTER transitions DROP NOT NULL');
        $this->addSql('ALTER TABLE field.field ALTER last_battle_info DROP NOT NULL');
        $this->addSql('ALTER TABLE field.field ALTER consumed_item_ids DROP NOT NULL');
        $this->addSql('ALTER TABLE game.game ALTER leaderboard DROP NOT NULL');
        $this->addSql('ALTER TABLE movement.movement ALTER player_positions DROP NOT NULL');
        $this->addSql('ALTER TABLE movement.movement ALTER transitions DROP NOT NULL');
        $this->addSql('ALTER TABLE movement.movement ALTER teleportation_connections DROP NOT NULL');
        $this->addSql('ALTER TABLE movement.movement ALTER movement_restrictions DROP NOT NULL');
        $this->addSql('ALTER TABLE movement.movement ALTER has_moved_after_battle DROP NOT NULL');
        $this->addSql('ALTER TABLE bag.bag ALTER items DROP NOT NULL');
        $this->addSql('ALTER TABLE game_turn.game_turn ALTER actions DROP NOT NULL');
        $this->addSql('ALTER TABLE player.player DROP is_ai');
        $this->addSql('ALTER TABLE player.player ALTER inventory DROP NOT NULL');
        $this->addSql('ALTER TABLE deck.deck ALTER tiles DROP NOT NULL');
        $this->addSql('ALTER TABLE battle.battle ALTER dice_results DROP NOT NULL');
    }
}
