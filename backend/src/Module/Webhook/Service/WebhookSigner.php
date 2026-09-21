<?php

declare(strict_types=1);

namespace App\Module\Webhook\Service;

/**
 * Standard Webhooks signing (standardwebhooks.com).
 *
 * A published scheme rather than one invented here, because the receiver is
 * somebody else's code: an off-the-shelf library should be able to verify these
 * without reading our documentation.
 *
 * The signed string is `{id}.{timestamp}.{payload}` - all three, deliberately:
 *
 *   • the **id** makes a replay identifiable as a replay;
 *   • the **timestamp** lets a receiver reject anything old, which is what
 *     actually stops a captured request being replayed tomorrow;
 *   • signing the payload alone would let either of the other two be swapped.
 */
final readonly class WebhookSigner
{
    /** What a receiver should allow for clock skew, and what our own verifier allows. */
    public const int TOLERANCE_SECONDS = 300;

    /**
     * @return array<string, string> the headers to send
     */
    public function headers(string $messageId, string $payload, string $secret, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            'webhook-id' => $messageId,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => 'v1,' . $this->sign($messageId, $timestamp, $payload, $secret),
        ];
    }

    public function sign(string $messageId, int $timestamp, string $payload, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', sprintf('%s.%d.%s', $messageId, $timestamp, $payload), $secret, true));
    }

    /**
     * Verify a signature the way a receiver should.
     *
     * Exists so the test suite checks what a third party would compute rather
     * than what we computed a moment ago - a self-consistent signature proves
     * nothing about interoperability.
     */
    public function verify(string $header, string $messageId, int $timestamp, string $payload, string $secret): bool
    {
        if (abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = 'v1,' . $this->sign($messageId, $timestamp, $payload, $secret);

        // Constant-time: a fast reject on the first differing byte leaks the
        // signature one character at a time.
        return hash_equals($expected, $header);
    }
}
