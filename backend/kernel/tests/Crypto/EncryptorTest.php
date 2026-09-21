<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use OpenEnu\Kernel\Crypto\DerivedKeyProvider;
use OpenEnu\Kernel\Crypto\Encryptor;

/**
 * Field encryption (ADR-0018).
 *
 * The tenant-isolation test is the one that matters: keys are derived per
 * tenant precisely so that a row read under the wrong scope is unreadable
 * rather than quietly wrong.
 */
#[CoversClass(Encryptor::class)]
#[CoversClass(DerivedKeyProvider::class)]
final class EncryptorTest extends TestCase
{
    private const string MASTER = 'a-master-key-of-at-least-32-characters!!';

    private function encryptor(string $master = self::MASTER): Encryptor
    {
        return new Encryptor(new DerivedKeyProvider($master));
    }

    public function testItRoundTripsAValue(): void
    {
        $e = $this->encryptor();
        $cipher = $e->encrypt('jan@example.com', 'tenant-a');

        self::assertNotSame('jan@example.com', $cipher);
        self::assertStringStartsWith('v1:', $cipher, 'The version prefix is what makes rotation survivable.');
        self::assertSame('jan@example.com', $e->decrypt($cipher, 'tenant-a'));
    }

    public function testTheSameValueEncryptsDifferentlyEachTime(): void
    {
        // A deterministic ciphertext would let anyone with read access to the
        // column tell which rows share a value - for an email column, that is
        // most of what the encryption was protecting.
        $e = $this->encryptor();

        self::assertNotSame(
            $e->encrypt('same', 'tenant-a'),
            $e->encrypt('same', 'tenant-a'),
        );
    }

    public function testAnotherTenantCannotDecryptIt(): void
    {
        $e = $this->encryptor();
        $cipher = $e->encrypt('tenant a secret', 'tenant-a');

        $this->expectException(\RuntimeException::class);
        $e->decrypt($cipher, 'tenant-b');
    }

    public function testTamperedCiphertextIsRejectedRatherThanMisread(): void
    {
        // GCM authenticates. Without that, a flipped bit would decrypt to
        // different plaintext and be used as if it were genuine.
        $e = $this->encryptor();
        $cipher = $e->encrypt('balance: 100', 'tenant-a');

        [$v, $iv, $ct, $tag] = explode(':', $cipher);
        $raw = base64_decode($ct, true);
        self::assertIsString($raw);
        $raw[0] = $raw[0] === 'A' ? 'B' : 'A';
        $tampered = implode(':', [$v, $iv, base64_encode($raw), $tag]);

        $this->expectException(\RuntimeException::class);
        $e->decrypt($tampered, 'tenant-a');
    }

    public function testADifferentMasterKeyCannotDecryptIt(): void
    {
        $cipher = $this->encryptor()->encrypt('secret', 'tenant-a');

        $this->expectException(\RuntimeException::class);
        $this->encryptor('a-completely-different-master-key-32ch')->decrypt($cipher, 'tenant-a');
    }

    public function testLookupHashIsStableAndCaseInsensitive(): void
    {
        // Login queries the hash column, so it must match regardless of how the
        // user typed their address.
        $e = $this->encryptor();

        self::assertSame(
            $e->hashForLookup('Jan@Example.com'),
            $e->hashForLookup('  jan@example.com  '),
        );
    }

    public function testAShortMasterKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DerivedKeyProvider('too-short');
    }

    public function testPlaintextInAnEncryptedColumnFailsLoudly(): void
    {
        // Adding #[Encrypted] to a column that already holds plaintext is a
        // real mistake; it must be an error and not silent corruption.
        $this->expectException(\RuntimeException::class);
        $this->encryptor()->decrypt('not encrypted at all', 'tenant-a');
    }
}
