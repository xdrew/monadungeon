<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250822051111 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IF EXISTS unique_player_wallet');
        $this->addSql('ALTER TABLE game.leaderboard ALTER wallet_address DROP NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX unique_player_identifier ON game.leaderboard (username, external_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX unique_player_identifier');
        $this->addSql('ALTER TABLE game.leaderboard ALTER wallet_address SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX unique_player_wallet ON game.leaderboard (wallet_address)');
    }
}
