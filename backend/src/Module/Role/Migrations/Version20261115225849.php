<?php

declare(strict_types=1);

namespace App\Module\Role\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115225849 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE role_role (id UUID NOT NULL, tenant_id UUID NOT NULL, role_key VARCHAR(40) NOT NULL, name VARCHAR(80) DEFAULT NULL, permissions JSONB NOT NULL, known JSONB NOT NULL, template VARCHAR(20) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_role_tenant ON role_role (tenant_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_role_tenant_key ON role_role (tenant_id, role_key)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE role_role');
    }
}
