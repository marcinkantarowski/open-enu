<?php

declare(strict_types=1);

namespace App\Module\ApiKey\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911113501 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_key (id UUID NOT NULL, tenant_id UUID NOT NULL, name VARCHAR(100) NOT NULL, key_hash VARCHAR(64) NOT NULL, key_prefix VARCHAR(16) NOT NULL, permissions JSONB NOT NULL, revoked BOOLEAN NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_api_key_tenant ON api_key (tenant_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_api_key_hash ON api_key (key_hash)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE api_key');
    }
}
