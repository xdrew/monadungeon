<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015130209 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove wallets';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // the production database never had this index under this name (its own history): drop it only if present
        $this->addSql('DROP INDEX IF EXISTS game.unique_player_wallet');
        $this->addSql('ALTER TABLE game.leaderboard DROP COLUMN IF EXISTS wallet_address');
        $this->addSql('ALTER TABLE player.player DROP COLUMN IF EXISTS wallet_address');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player.player ADD wallet_address VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE game.leaderboard ADD wallet_address VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX unique_player_wallet ON game.leaderboard (wallet_address)');
    }
}
