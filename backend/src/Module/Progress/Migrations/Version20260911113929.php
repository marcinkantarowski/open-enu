<?php

declare(strict_types=1);

namespace App\Module\Progress\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911113929 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE progress_job (id VARCHAR(32) NOT NULL, tenant_id UUID NOT NULL, kind VARCHAR(64) NOT NULL, label VARCHAR(200) DEFAULT NULL, total INT NOT NULL, done INT NOT NULL, status VARCHAR(16) NOT NULL, result JSONB NOT NULL, failure_reason TEXT DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_progress_tenant_status ON progress_job (tenant_id, status)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE progress_job');
    }
}
