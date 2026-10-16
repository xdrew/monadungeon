<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016112216 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game.game ADD online BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE game.game ADD host_account_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE game.game ADD seats INT DEFAULT NULL');
        $this->addSql('ALTER TABLE game.game ADD end_reason VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game.game DROP online');
        $this->addSql('ALTER TABLE game.game DROP host_account_id');
        $this->addSql('ALTER TABLE game.game DROP seats');
        $this->addSql('ALTER TABLE game.game DROP end_reason');
    }
}
