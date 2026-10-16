<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016123013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE account.session (id UUID NOT NULL, account_id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_session_account ON account.session (account_id)');
        $this->addSql('CREATE UNIQUE INDEX unique_session_token_hash ON account.session (token_hash)');
        $this->addSql('COMMENT ON COLUMN account.session.id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN account.session.account_id IS \'(DC2Type:App\\Infrastructure\\Uuid\\DoctrineDBAL\\UuidType)\'');
        $this->addSql('COMMENT ON COLUMN account.session.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE account.account ADD google_sub VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE account.account ADD google_email VARCHAR(320) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX unique_account_google_sub ON account.account (google_sub)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE account.session');
        $this->addSql('DROP INDEX unique_account_google_sub');
        $this->addSql('ALTER TABLE account.account DROP google_sub');
        $this->addSql('ALTER TABLE account.account DROP google_email');
    }
}
