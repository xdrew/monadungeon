<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016202852 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE battle.battle ADD duel_preset jsonb');
        $this->addSql('ALTER TABLE duel.duel ADD challenger_prep jsonb');
        $this->addSql('ALTER TABLE duel.duel ADD opponent_prep jsonb');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE battle.battle DROP duel_preset');
        $this->addSql('ALTER TABLE duel.duel DROP challenger_prep');
        $this->addSql('ALTER TABLE duel.duel DROP opponent_prep');
    }
}
