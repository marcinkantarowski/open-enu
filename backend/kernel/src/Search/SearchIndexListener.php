<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Search;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use OpenEnu\Kernel\Contract\TenantScopedInterface;

/**
 * Keeps the index in step with the database, on write.
 *
 * A listener rather than a call in each handler, because the alternative is a
 * line every author has to remember in every write path - and the one that is
 * forgotten produces a record that exists and cannot be found, which nobody
 * reports as a bug.
 *
 * Reindexing is a full rewrite of the row's document rather than a diff: the
 * indexed text is derived from several fields, so "which fields changed" is not
 * the question that matters.
 *
 * The tenant comes from the ENTITY, never from the ambient scope. A write inside
 * `runUnscoped()` - a fixture, an operator action, a GDPR erasure - is perfectly
 * legitimate, and taking the tenant from the scope there either throws and kills
 * the write or silently indexes into the wrong partition.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
final readonly class SearchIndexListener
{
    public function __construct(
        private SearchIndexerInterface $indexer,
        private SearchCatalogue $catalogue,
    ) {
    }

    public function postPersist(PostPersistEventArgs $event): void
    {
        $this->reindex($event->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $event): void
    {
        $this->reindex($event->getObject());
    }

    public function postRemove(PostRemoveEventArgs $event): void
    {
        $entity = $event->getObject();

        if ($this->catalogue->for($entity::class) === null || !$entity instanceof TenantScopedInterface) {
            return;
        }

        // An index entry outliving its record is a search result that 404s.
        $this->indexer->remove($entity::class, $this->identify($entity), $entity->tenantId());
    }

    private function reindex(object $entity): void
    {
        $declaration = $this->catalogue->for($entity::class);

        // Only tenant-scoped entities are indexable: the index is partitioned by
        // tenant, so a row with no tenant has no partition to go in. Declaring an
        // unscoped entity in `search.php` is caught by the search suite.
        if ($declaration === null || !$entity instanceof TenantScopedInterface) {
            return;
        }

        $reflection = new \ReflectionObject($entity);
        $values = [];

        foreach ($declaration['fields'] as $field) {
            if (!$reflection->hasProperty($field)) {
                continue;
            }

            $value = $reflection->getProperty($field)->getValue($entity);
            $values[$field] = \is_scalar($value) ? (string) $value : '';
        }

        $this->indexer->index($entity::class, $this->identify($entity), $values, $entity->tenantId());
    }

    private function identify(object $entity): string
    {
        // Convention, not configuration: every entity here has an `id()`.
        return method_exists($entity, 'id') ? (string) $entity->id() : '';
    }
}
