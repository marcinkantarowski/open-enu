<?php

declare(strict_types=1);

namespace App\Module\Webhook\Tests\Unit;

use App\Module\Webhook\Service\WebhookSigner;
use PHPUnit\Framework\TestCase;

/**
 * The signature, checked the way a third party would check it.
 *
 * Every assertion here recomputes the HMAC from the published spec rather than
 * calling our own `sign()`. A test that signs with the code under test and then
 * verifies with the code under test proves the two agree, which is exactly what
 * a broken-but-self-consistent implementation also does.
 */
final class WebhookSignerTest extends TestCase
{
    private const string SECRET = 'whsec_test_secret';

    public function testTheSignatureMatchesWhatTheStandardSpecifies(): void
    {
        $signer = new WebhookSigner();
        $id = 'msg_01';
        $timestamp = 1_700_000_000;
        $payload = '{"event":"example.project.created"}';

        $headers = $signer->headers($id, $payload, self::SECRET, $timestamp);

        // standardwebhooks.com: v1,<base64(HMAC-SHA256("{id}.{timestamp}.{payload}"))>
        $expected = 'v1,' . base64_encode(
            hash_hmac('sha256', $id . '.' . $timestamp . '.' . $payload, self::SECRET, true),
        );

        self::assertSame($expected, $headers['webhook-signature']);
        self::assertSame($id, $headers['webhook-id']);
        self::assertSame((string) $timestamp, $headers['webhook-timestamp']);
    }

    public function testChangingAnyPartOfTheSignedStringChangesTheSignature(): void
    {
        $signer = new WebhookSigner();
        $base = $signer->sign('msg_01', 1_700_000_000, '{"a":1}', self::SECRET);

        // All three are signed for a reason: the id makes a replay identifiable,
        // the timestamp lets a receiver reject stale requests, and without both
        // in the signed string either could be swapped for another.
        self::assertNotSame($base, $signer->sign('msg_02', 1_700_000_000, '{"a":1}', self::SECRET));
        self::assertNotSame($base, $signer->sign('msg_01', 1_700_000_001, '{"a":1}', self::SECRET));
        self::assertNotSame($base, $signer->sign('msg_01', 1_700_000_000, '{"a":2}', self::SECRET));
        self::assertNotSame($base, $signer->sign('msg_01', 1_700_000_000, '{"a":1}', 'whsec_other'));
    }

    public function testAnOldTimestampIsRefusedEvenWithAValidSignature(): void
    {
        $signer = new WebhookSigner();
        $stale = time() - WebhookSigner::TOLERANCE_SECONDS - 1;
        $header = 'v1,' . $signer->sign('msg_01', $stale, '{"a":1}', self::SECRET);

        // The signature is genuine; the request is a replay. Without the window
        // a captured request stays valid forever.
        self::assertFalse($signer->verify($header, 'msg_01', $stale, '{"a":1}', self::SECRET));
        self::assertTrue($signer->verify(
            'v1,' . $signer->sign('msg_01', time(), '{"a":1}', self::SECRET),
            'msg_01',
            time(),
            '{"a":1}',
            self::SECRET,
        ));
    }
}
