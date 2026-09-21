<?php

declare(strict_types=1);

namespace App\Module\Audit\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911113926 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_entry (id UUID NOT NULL, action VARCHAR(100) NOT NULL, tenant_id VARCHAR(64) DEFAULT NULL, actor_id VARCHAR(64) DEFAULT NULL, on_behalf_of_id VARCHAR(64) DEFAULT NULL, subject_id VARCHAR(64) DEFAULT NULL, request_id VARCHAR(64) DEFAULT NULL, succeeded BOOLEAN NOT NULL, failure_reason TEXT DEFAULT NULL, before JSONB DEFAULT NULL, after JSONB DEFAULT NULL, context JSONB NOT NULL, recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_audit_tenant_time ON audit_entry (tenant_id, recorded_at)');
        $this->addSql('CREATE INDEX idx_audit_action ON audit_entry (action)');
        $this->addSql('CREATE INDEX idx_audit_actor ON audit_entry (actor_id)');
        $this->addSql('CREATE INDEX idx_audit_subject ON audit_entry (subject_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE audit_entry');
    }
}
