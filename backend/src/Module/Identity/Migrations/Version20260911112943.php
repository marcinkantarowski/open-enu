<?php

declare(strict_types=1);

namespace App\Module\Identity\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911112943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE identity_membership (id UUID NOT NULL, tenant_id UUID NOT NULL, role VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_membership_tenant ON identity_membership (tenant_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_membership_user_tenant ON identity_membership (user_id, tenant_id)');
        $this->addSql('CREATE INDEX IDX_FE1AE0B6A76ED395 ON identity_membership (user_id)');
        $this->addSql('CREATE TABLE identity_refresh_token (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, family UUID NOT NULL, tenant_id UUID DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_refresh_family ON identity_refresh_token (family)');
        $this->addSql('CREATE UNIQUE INDEX uniq_refresh_token_hash ON identity_refresh_token (token_hash)');
        $this->addSql('CREATE INDEX IDX_DC7238A2A76ED395 ON identity_refresh_token (user_id)');
        $this->addSql('CREATE TABLE identity_security_token (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, purpose VARCHAR(32) NOT NULL, tenant_id UUID DEFAULT NULL, payload JSONB NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_security_token_purge ON identity_security_token (purpose, expires_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_security_token_hash ON identity_security_token (token_hash)');
        $this->addSql('CREATE INDEX IDX_B2DDA87A76ED395 ON identity_security_token (user_id)');
        $this->addSql('CREATE TABLE identity_user (id UUID NOT NULL, email TEXT NOT NULL, email_hash VARCHAR(64) NOT NULL, password VARCHAR(255) NOT NULL, display_name TEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, locale VARCHAR(5) DEFAULT NULL, avatar_key VARCHAR(255) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_user_status ON identity_user (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email_hash ON identity_user (email_hash)');
        $this->addSql('ALTER TABLE identity_membership ADD CONSTRAINT FK_FE1AE0B6A76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE identity_refresh_token ADD CONSTRAINT FK_DC7238A2A76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE identity_security_token ADD CONSTRAINT FK_B2DDA87A76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE identity_membership DROP CONSTRAINT FK_FE1AE0B6A76ED395');
        $this->addSql('ALTER TABLE identity_refresh_token DROP CONSTRAINT FK_DC7238A2A76ED395');
        $this->addSql('ALTER TABLE identity_security_token DROP CONSTRAINT FK_B2DDA87A76ED395');
        $this->addSql('DROP TABLE identity_membership');
        $this->addSql('DROP TABLE identity_refresh_token');
        $this->addSql('DROP TABLE identity_security_token');
        $this->addSql('DROP TABLE identity_user');
    }
}
