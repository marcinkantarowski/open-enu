<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use OpenEnu\Kernel\Crypto\DerivedKeyProvider;

#[CoversClass(DerivedKeyProvider::class)]
final class DerivedKeyProviderTest extends TestCase
{
    private const string MASTER = 'a-master-key-of-at-least-32-characters!!';

    public function testEachTenantGetsADistinctKey(): void
    {
        $p = new DerivedKeyProvider(self::MASTER);

        self::assertNotSame($p->keyFor('tenant-a'), $p->keyFor('tenant-b'));
    }

    public function testTheKeyIsStableForATenant(): void
    {
        // Derivation is a pure function, which is what removes the need for a
        // key table - and what makes it catastrophic to change the master key
        // without re-encrypting.
        $a = new DerivedKeyProvider(self::MASTER);
        $b = new DerivedKeyProvider(self::MASTER);

        self::assertSame($a->keyFor('tenant-a'), $b->keyFor('tenant-a'));
    }

    public function testTheKeyIsTheRightLengthForAes256(): void
    {
        self::assertSame(32, \strlen((new DerivedKeyProvider(self::MASTER))->keyFor('t')));
    }

    public function testNoTenantStillYieldsAKey(): void
    {
        // Unscoped rows - reference data, the tenant table itself - still need
        // encryptable columns.
        self::assertNotSame('', (new DerivedKeyProvider(self::MASTER))->keyFor(null));
    }
}
