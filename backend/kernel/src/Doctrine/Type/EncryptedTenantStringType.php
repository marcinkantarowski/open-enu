<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Doctrine\Type;

/**
 * An encrypted column keyed by the tenant that owns the row.
 *
 *     #[ORM\Column(type: EncryptedTenantStringType::NAME)]
 *     private string $internalNote;
 *
 * **Only valid on an entity implementing TenantScopedInterface.** The key comes
 * from the ambient scope, which is the row's tenant only because the query
 * filter guarantees a scoped entity is read under its own tenant. On an unscoped
 * entity that guarantee does not exist, and the column becomes unreadable the
 * moment the ambient scope differs from what it was at write time - use
 * {@see EncryptedStringType} there.
 *
 * What this buys over the global key (ADR-0018): a tenant's data can be made
 * permanently unreadable by rotating that tenant's salt alone - cryptographic
 * erasure, which is a genuinely useful answer to a deletion request.
 */
final class EncryptedTenantStringType extends EncryptedStringType
{
    public const string NAME = 'encrypted_tenant_string';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function keyScope(): ?string
    {
        // Null when nothing is in scope - writes then use the global key, and a
        // row written that way stays readable, which is the right failure mode.
        return self::$scope?->tenantId();
    }
}
