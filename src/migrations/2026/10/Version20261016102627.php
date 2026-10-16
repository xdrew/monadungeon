<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016102627 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA account');
        $this->addSql('CREATE TABLE account.account (id UUID NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(24) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX unique_account_token_hash ON account.account (token_hash)');
        $this->addSql('COMMENT ON COLUMN account.account.id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN account.account.last_seen_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN account.account.created_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE account.account');
    }
}
