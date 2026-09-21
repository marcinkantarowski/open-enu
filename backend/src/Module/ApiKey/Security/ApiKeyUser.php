<?php

declare(strict_types=1);

namespace App\Module\ApiKey\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The principal behind a key - a machine, not a person.
 *
 * Deliberately NOT the User entity: an integration is not a person, it should
 * not inherit a person's permissions, and attributing its actions to whoever
 * created the key makes the audit trail lie. Roles are derived from the key's
 * own permission subset and nothing else.
 */
final readonly class ApiKeyUser implements UserInterface
{
    /** @param list<string> $permissions */
    public function __construct(
        private string $keyId,
        private string $tenantId,
        private array $permissions,
    ) {
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function getUserIdentifier(): string
    {
        return 'api_key:' . $this->keyId;
    }

    /**
     * ROLE_API_KEY, never ROLE_USER: a key must not satisfy a check written for
     * a human. Anything finer is a permission, checked explicitly.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_API_KEY'];
    }

    /**
     * Nothing transient is held - the key hash is the stored value and this
     * object never sees the secret.
     */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
