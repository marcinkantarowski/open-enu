<?php

declare(strict_types=1);

namespace App\Module\Notification\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Notification: the per-user feed. */
final class Version20260911183743 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification: the per-user feed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification (tenant_id UUID NOT NULL, id UUID NOT NULL, user_id UUID NOT NULL, type VARCHAR(120) NOT NULL, context JSONB NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_notification_tenant ON notification (tenant_id)');
        $this->addSql('CREATE INDEX idx_notification_user ON notification (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification');
    }
}
