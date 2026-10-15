<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015095541 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Karak: Regent — Lord Karak variant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field.field ADD lord_karak_rooms INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE game.game ADD lord_karak BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE game.game ADD lord_karak_player_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE game.game ADD lord_karak_reveal jsonb');
        $this->addSql('COMMENT ON COLUMN game.game.lord_karak_player_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('ALTER TABLE player.player ADD lord_karak BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE player.player ADD lord_karak_heroes JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field.field DROP lord_karak_rooms');
        $this->addSql('ALTER TABLE player.player DROP lord_karak');
        $this->addSql('ALTER TABLE player.player DROP lord_karak_heroes');
        $this->addSql('ALTER TABLE game.game DROP lord_karak');
        $this->addSql('ALTER TABLE game.game DROP lord_karak_player_id');
        $this->addSql('ALTER TABLE game.game DROP lord_karak_reveal');
    }
}
