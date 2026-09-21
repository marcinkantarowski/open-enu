<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Crypto;

/**
 * AES-256-GCM, with the tenant's derived key.
 *
 * GCM rather than CBC because it authenticates: ciphertext that has been
 * tampered with fails to decrypt instead of silently producing different
 * plaintext.
 *
 * Payload format `v1:iv:ciphertext:tag`, base64 per part. The version prefix is
 * what makes a future algorithm change survivable - old rows stay readable while
 * new ones are written differently, so rotation is a background job rather than
 * a migration window.
 */
final readonly class Encryptor
{
    private const string CIPHER = 'aes-256-gcm';
    private const string VERSION = 'v1';
    private const int IV_BYTES = 12;
    private const int TAG_BYTES = 16;

    public function __construct(private KeyProviderInterface $keys)
    {
    }

    public function encrypt(string $plaintext, ?string $tenantId): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->keys->keyFor($tenantId),
            \OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return implode(':', [
            self::VERSION,
            base64_encode($iv),
            base64_encode($ciphertext),
            base64_encode($tag),
        ]);
    }

    public function decrypt(string $payload, ?string $tenantId): string
    {
        $parts = explode(':', $payload, 4);
        if (\count($parts) !== 4 || $parts[0] !== self::VERSION) {
            throw new \RuntimeException(
                'Not a recognised encrypted payload. A plaintext value in an #[Encrypted] '
                . 'column usually means the column was added before the attribute - backfill it.',
            );
        }

        [, $iv, $ciphertext, $tag] = $parts;

        $plaintext = openssl_decrypt(
            base64_decode($ciphertext, true) ?: '',
            self::CIPHER,
            $this->keys->keyFor($tenantId),
            \OPENSSL_RAW_DATA,
            base64_decode($iv, true) ?: '',
            base64_decode($tag, true) ?: '',
        );

        if ($plaintext === false) {
            // Wrong key, wrong tenant, or tampering - GCM cannot tell you which,
            // which is the point.
            throw new \RuntimeException(
                'Decryption failed. Either the master key changed, or this row belongs to a '
                . 'different tenant than the current scope.',
            );
        }

        return $plaintext;
    }

    /**
     * Deterministic hash for equality lookups on an encrypted column.
     *
     * Encrypted values differ every time they are written (random IV), so they
     * cannot be searched. A sibling `*_hash` column holds this instead, which is
     * how login finds a user by an encrypted email address.
     *
     * Equality only. It leaks nothing about the plaintext beyond "these two rows
     * hold the same value", which is exactly what an index needs.
     */
    public function hashForLookup(string $value): string
    {
        return hash('sha256', mb_strtolower(trim($value)));
    }
}
