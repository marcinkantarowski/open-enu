<?php

declare(strict_types=1);

namespace App\Module\Webhook\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Webhook: endpoints and their delivery log. */
final class Version20260911174537 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Webhook: endpoints and their delivery log.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE webhook_delivery (tenant_id UUID NOT NULL, id UUID NOT NULL, endpoint_id UUID NOT NULL, event_name VARCHAR(120) NOT NULL, payload JSONB NOT NULL, status VARCHAR(16) NOT NULL, attempts INT NOT NULL, response_code INT DEFAULT NULL, response_body TEXT DEFAULT NULL, last_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_webhook_delivery_tenant ON webhook_delivery (tenant_id)');
        $this->addSql('CREATE INDEX idx_webhook_delivery_endpoint ON webhook_delivery (endpoint_id)');
        $this->addSql('CREATE TABLE webhook_endpoint (tenant_id UUID NOT NULL, id UUID NOT NULL, url VARCHAR(500) NOT NULL, events JSONB NOT NULL, secret TEXT NOT NULL, active BOOLEAN NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_webhook_endpoint_tenant ON webhook_endpoint (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE webhook_delivery');
        $this->addSql('DROP TABLE webhook_endpoint');
    }
}
