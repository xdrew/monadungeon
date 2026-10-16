<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016120211 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game.game ADD turn_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE game.game ADD turn_deadline TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN game.game.turn_deadline IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('ALTER TABLE player.player ADD away BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player.player DROP away');
        $this->addSql('ALTER TABLE game.game DROP turn_seconds');
        $this->addSql('ALTER TABLE game.game DROP turn_deadline');
    }
}
