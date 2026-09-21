<?php

declare(strict_types=1);

namespace App\Module\Tenant\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911112001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tenant (id UUID NOT NULL, slug VARCHAR(64) NOT NULL, name VARCHAR(200) NOT NULL, status VARCHAR(20) NOT NULL, plan VARCHAR(40) NOT NULL, default_locale VARCHAR(5) NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, activated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4E59C462989D9B62 ON tenant (slug)');
        $this->addSql('CREATE INDEX idx_tenant_status ON tenant (status)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE tenant');
    }
}
