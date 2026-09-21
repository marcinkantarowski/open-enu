<?php

declare(strict_types=1);

namespace App\Module\Example\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Adds the searchable description column (Phase 7). */
final class Version20260911174003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Example: a searchable description on the project.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE example_project ADD description TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE example_project DROP description');
    }
}
