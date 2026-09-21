<?php

declare(strict_types=1);

namespace App\Module\Attachment\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911113928 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE attachment (id UUID NOT NULL, tenant_id UUID NOT NULL, storage_key VARCHAR(255) NOT NULL, filename VARCHAR(255) NOT NULL, content_type VARCHAR(127) NOT NULL, size BIGINT NOT NULL, owner_type VARCHAR(64) DEFAULT NULL, owner_id VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_attachment_owner ON attachment (tenant_id, owner_type, owner_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE attachment');
    }
}
