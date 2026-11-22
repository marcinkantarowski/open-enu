<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Search that ignores accents: "zajac" finds "Zając", "lodz" finds "Łódź".
 *
 * A text search configuration of the kernel's own, `open_enu_search`: the
 * `simple` one with `unaccent` in front of it for words with non-ASCII letters.
 * Both the stored documents and the queries go through it, so the match is
 * accent-blind while `content` - and the excerpt cut from it - keeps the letters
 * as they were typed. `unaccent` is a trusted extension (Postgres 13+), so the
 * database owner may create it without a superuser.
 *
 * Every row is re-parsed here: a document built with the old configuration
 * would never match an unaccented query. Idempotent, so a database that already
 * has the configuration only re-parses.
 *
 * Stamped in the past for the reason Version20200101000000 gives.
 */
final class Version20200101000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kernel: accent-blind search configuration, documents re-parsed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_ts_config WHERE cfgname = 'open_enu_search') THEN
                    CREATE TEXT SEARCH CONFIGURATION open_enu_search (COPY = simple);
                    ALTER TEXT SEARCH CONFIGURATION open_enu_search
                        ALTER MAPPING FOR hword, hword_part, word WITH unaccent, simple;
                END IF;
            END
            $$
            SQL);
        $this->addSql("UPDATE search_index SET document = to_tsvector('open_enu_search', content)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE search_index SET document = to_tsvector('simple', content)");
        $this->addSql('DROP TEXT SEARCH CONFIGURATION IF EXISTS open_enu_search');
    }
}
