<?php

declare(strict_types=1);

namespace App\Module\Tenant\Contract;

/**
 * What other modules may know about a tenant.
 *
 * Deliberately narrow, and deliberately returning plain data rather than the
 * entity: handing out the entity would let another module mutate a tenant
 * without going through this module's commands, which is exactly the coupling
 * the boundary rules exist to prevent.
 */
interface TenantReaderInterface
{
    public function exists(string $tenantId): bool;

    public function isActive(string $tenantId): bool;

    /** @return array{id: string, slug: string, name: string, status: string, defaultLocale: string}|null */
    public function describe(string $tenantId): ?array;

    public function defaultLocale(string $tenantId): ?string;

    /**
     * Every tenant, for the operator console.
     *
     * Deliberately on the reader rather than a separate admin contract: it is
     * still a read, and the authority to make it comes from the caller's realm -
     * only `^/api/manager` can reach a caller allowed to ask.
     *
     * @return list<array<string, mixed>>
     */
    public function page(int $offset, int $limit): array;

    public function total(): int;
}
