<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The search index learns which unit a record is in (ADR-0025).
 *
 * The index is read by DBAL, which the scope filter cannot see, so without
 * this someone narrowed to one site finds every site's records - with an
 * excerpt. `unit_scoped` tells a tenant-wide entity (always visible) from a
 * unit-scoped one with no unit (visible to the whole-tenant only).
 *
 * Stamped in the past for the reason Version20200101000000 gives.
 */
final class Version20200101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kernel: unit of each search index entry (ADR-0025)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE search_index ADD unit_scoped BOOLEAN NOT NULL DEFAULT false, ADD unit_id VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE search_index DROP unit_scoped, DROP unit_id');
    }
}
