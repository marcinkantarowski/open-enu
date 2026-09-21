<?php

declare(strict_types=1);

namespace App\Module\Settings\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911121953 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE setting (id UUID NOT NULL, identifier VARCHAR(120) NOT NULL, name VARCHAR(200) NOT NULL, description TEXT DEFAULT NULL, type VARCHAR(16) NOT NULL, default_value JSONB NOT NULL, tenant_editable BOOLEAN NOT NULL, category VARCHAR(64) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_setting_identifier ON setting (identifier)');
        $this->addSql('CREATE TABLE setting_override (id UUID NOT NULL, tenant_id UUID NOT NULL, value JSONB NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, setting_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_override_tenant_setting ON setting_override (tenant_id, setting_id)');
        $this->addSql('CREATE INDEX IDX_2C8A3E1FEE35BD72 ON setting_override (setting_id)');
        $this->addSql('ALTER TABLE setting_override ADD CONSTRAINT FK_2C8A3E1FEE35BD72 FOREIGN KEY (setting_id) REFERENCES setting (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE setting_override DROP CONSTRAINT FK_2C8A3E1FEE35BD72');
        $this->addSql('DROP TABLE setting');
        $this->addSql('DROP TABLE setting_override');
    }
}
