<?php

declare(strict_types=1);

use App\Module\Example\Entity\Project;

/**
 * What of this module's data is findable, and who may find it.
 *
 * The permission is required, not optional: search returns an *excerpt*, which
 * is content, so a hit the caller may not read is a disclosure. It is applied
 * before the query runs, not to the results.
 *
 * `clientReference` is deliberately absent. It is encrypted at rest, and the
 * index stores plaintext - indexing it would move the secret into a table that
 * has no access control of its own.
 */
return [
    Project::class => [
        'fields' => ['name', 'description'],
        'permission' => 'example.view',
    ],
];
