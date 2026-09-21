<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\TextType;
use OpenEnu\Kernel\Crypto\Encryptor;
use OpenEnu\Kernel\Doctrine\ScopeContext;

/**
 * A column that is ciphertext at rest and plaintext in PHP.
 *
 *     #[ORM\Column(type: EncryptedStringType::NAME)]
 *     private string $internalNote;
 *
 * Transparent by design: making developers remember to encrypt is how columns
 * end up unencrypted. The cost is that the value cannot be searched or sorted in
 * SQL - use a sibling `*_hash` column for equality (see Encryptor).
 *
 * **Uses the global key**, deliberately. Which key a row was written with must
 * be a property of the ROW, and a Doctrine type cannot see the entity - it sees
 * only the value. Deriving from the ambient tenant scope instead makes a column
 * readable only when the scope happens to match what it was at write time,
 * which is fine for tenant-scoped entities and silently broken for everything
 * else: `User` is not tenant-scoped, so its encrypted email would be written
 * during signup under one scope and read during login under another, failing
 * with an error that blames the master key.
 *
 * For data that genuinely belongs to one tenant, use
 * {@see EncryptedTenantStringType} - on an entity that is TenantScoped, the
 * ambient scope IS the row's tenant, and per-tenant keys then hold.
 *
 * Doctrine types are instantiated by the DBAL without the container, so the
 * collaborators are injected statically at boot. Unusual, and the only way to
 * give a DBAL type access to services.
 */
class EncryptedStringType extends TextType
{
    public const string NAME = 'encrypted_string';

    protected static ?Encryptor $encryptor = null;
    protected static ?ScopeContext $scope = null;

    public static function configure(Encryptor $encryptor, ScopeContext $scope): void
    {
        self::$encryptor = $encryptor;
        self::$scope = $scope;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null || $value === '') {
            return $value === null ? null : '';
        }

        return $this->encryptor()->encrypt((string) $value, $this->keyScope());
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null || $value === '') {
            return $value === null ? null : '';
        }

        return $this->encryptor()->decrypt((string) $value, $this->keyScope());
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }

    /**
     * Which key this column uses. Null means the global key.
     *
     * Overridden by EncryptedTenantStringType, which is the only safe place to
     * key by tenant - see the class docblock.
     */
    protected function keyScope(): ?string
    {
        return null;
    }

    protected function encryptor(): Encryptor
    {
        return self::$encryptor ?? throw new \LogicException(
            'EncryptedStringType was used before the kernel configured it. '
            . 'This happens if Doctrine is bootstrapped outside the Symfony container.',
        );
    }
}
