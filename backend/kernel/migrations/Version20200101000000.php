<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The kernel's own schema: the full-text search index.
 *
 * Infrastructure rather than a module's data, so the kernel owns it. It is
 * excluded from `doctrine:schema:validate` by the schema filter, because no
 * entity maps it - it is written by DBAL for performance.
 *
 * The version is deliberately far in the past. Doctrine orders migrations by
 * version across every registered namespace and refuses one that sorts BEFORE
 * the latest applied - so a kernel migration stamped "now" would silently block
 * every module migration generated earlier the same day.
 */
final class Version20200101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kernel: full-text search index (ADR-0021)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE search_index (
                tenant_id   VARCHAR(64)  NOT NULL,
                entity_type VARCHAR(128) NOT NULL,
                entity_id   VARCHAR(64)  NOT NULL,
                content     TEXT         NOT NULL,
                document    TSVECTOR     NOT NULL,
                indexed_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
                PRIMARY KEY (tenant_id, entity_type, entity_id)
            )
            SQL);

        // GIN over the tsvector is what makes @@ fast; without it every search
        // is a sequential scan and the feature quietly becomes unusable at size.
        $this->addSql('CREATE INDEX idx_search_document ON search_index USING GIN (document)');

        // Every query filters by tenant first.
        $this->addSql('CREATE INDEX idx_search_tenant ON search_index (tenant_id, entity_type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE search_index');
    }
}
