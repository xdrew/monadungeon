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
        $this->addSql('DROP INDEX game.unique_player_wallet');
        $this->addSql('ALTER TABLE game.leaderboard DROP wallet_address');
        $this->addSql('ALTER TABLE player.player DROP wallet_address');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player.player ADD wallet_address VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE game.leaderboard ADD wallet_address VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX unique_player_wallet ON game.leaderboard (wallet_address)');
    }
}
