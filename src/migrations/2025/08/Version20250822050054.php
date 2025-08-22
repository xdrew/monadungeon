<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250822050054 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE game.leaderboard (id UUID NOT NULL, username VARCHAR(255) NOT NULL, wallet_address VARCHAR(255) NOT NULL, external_id VARCHAR(255) DEFAULT NULL, victories INT NOT NULL, total_games INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_leaderboard_username ON game.leaderboard (username)');
        $this->addSql('CREATE INDEX idx_leaderboard_external_id ON game.leaderboard (external_id)');
        $this->addSql('CREATE INDEX idx_leaderboard_victories ON game.leaderboard (victories)');
        $this->addSql('CREATE INDEX idx_leaderboard_total_games ON game.leaderboard (total_games)');
        $this->addSql('CREATE UNIQUE INDEX unique_player_wallet ON game.leaderboard (wallet_address)');
        $this->addSql('COMMENT ON COLUMN game.leaderboard.id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN game.leaderboard.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN game.leaderboard.updated_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE game.leaderboard');
    }
}
