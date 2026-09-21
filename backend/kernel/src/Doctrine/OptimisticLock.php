<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Doctrine;

use OpenEnu\Kernel\Contract\VersionedInterface;
use OpenEnu\Kernel\Dto\ConflictBody;
use OpenEnu\Kernel\Http\Exception\ConflictException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Refuses a write built on a stale read.
 *
 * The check is explicit rather than automatic because only the caller knows
 * which record the client was looking at. What is NOT optional is the arch test
 * requiring every PUT/PATCH entity to be VersionedInterface - the mechanism is
 * mandatory, its invocation is local.
 *
 * Version travels in `If-Match`, which is what the header is for. A body field
 * is accepted as a fallback because form-based clients cannot easily set
 * headers, and a rule people cannot follow gets skipped.
 */
final readonly class OptimisticLock
{
    public const string HEADER = 'If-Match';
    public const string BODY_FIELD = 'version';

    /**
     * @param array<string, mixed> $payload    the decoded request body
     * @param callable():array<string, mixed>|null $describe current state for the conflict body
     *
     * @throws ConflictException when the client's version is not the current one
     */
    public function assertCurrent(
        VersionedInterface $entity,
        Request $request,
        array $payload = [],
        ?callable $describe = null,
    ): void {
        $expected = $this->expectedVersion($request, $payload);

        // No version supplied: the client is not participating in locking. That
        // is allowed - a background job or an internal call has no stale read to
        // protect against - but it is why the HEADER is documented as the way a
        // UI must write.
        if ($expected === null) {
            return;
        }

        if ($expected === $entity->version()) {
            return;
        }

        throw new ConflictException(new ConflictBody(
            resource: (new \ReflectionClass($entity))->getShortName(),
            resourceId: method_exists($entity, 'id') ? (string) $entity->id() : null,
            yourVersion: $expected,
            currentVersion: $entity->version(),
            current: $describe !== null ? $describe() : null,
        ));
    }

    /** @param array<string, mixed> $payload */
    private function expectedVersion(Request $request, array $payload): ?int
    {
        $header = $request->headers->get(self::HEADER);
        if ($header !== null && $header !== '') {
            // ETags are quoted, and a weak validator is prefixed W/. Accept both
            // rather than failing on a correctly-formed header.
            $value = trim($header, '"');
            $value = preg_replace('/^W\/"?|"?$/', '', $value) ?? $value;
            if (ctype_digit($value)) {
                return (int) $value;
            }
        }

        $fromBody = $payload[self::BODY_FIELD] ?? null;

        return \is_int($fromBody) || (\is_string($fromBody) && ctype_digit($fromBody))
            ? (int) $fromBody
            : null;
    }
}
