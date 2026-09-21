<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Ulid;

/**
 * The correlation id that ties an HTTP request to every log line, background
 * message and audit entry it causes.
 *
 * Without one, diagnosing anything that crosses into a worker means guessing by
 * timestamp. A ULID rather than a UUID because it sorts by creation time, which
 * makes a log file readable in the order things happened.
 *
 * Assigned by RequestIdListener on every request, echoed back in
 * `X-Request-Id`, and carried into async work by RequestIdStamp.
 */
final class RequestId
{
    public const string HEADER = 'X-Request-Id';
    public const string ATTRIBUTE = '_open_enu_request_id';

    public static function generate(): string
    {
        return (new Ulid())->toBase32();
    }

    public static function fromRequest(Request $request): ?string
    {
        $id = $request->attributes->get(self::ATTRIBUTE);

        return \is_string($id) ? $id : null;
    }

    /**
     * A client-supplied id is accepted so a trace can span services, but it is
     * validated first: it ends up in log files, so an unvalidated value is a log
     * injection vector.
     */
    public static function sanitize(?string $candidate): ?string
    {
        if ($candidate === null || $candidate === '') {
            return null;
        }
        if (\strlen($candidate) > 64 || preg_match('/^[A-Za-z0-9._-]+$/', $candidate) !== 1) {
            return null;
        }

        return $candidate;
    }
}
