<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Dto;

/**
 * The single error shape every API endpoint returns.
 *
 * One shape, always, so a client writes one error path and an agent writing a
 * frontend never has to discover which of four formats an endpoint uses. The
 * envelope is `{"error": {...}}` rather than a bare object so a response is
 * never ambiguous between success and failure at the top level.
 *
 * `code` is a stable machine token (snake_case) - clients branch on it.
 * `message` is human-readable and translated, and MAY change; nothing should
 * branch on it.
 */
final readonly class ErrorResponse implements \JsonSerializable
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public string $code,
        public string $message,
        public int $status = 500,
        public array $details = [],
        public ?string $requestId = null,
    ) {
    }

    /** @return array{error: array<string, mixed>} */
    public function jsonSerialize(): array
    {
        $error = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->details !== []) {
            $error['details'] = $this->details;
        }
        if ($this->requestId !== null) {
            $error['requestId'] = $this->requestId;
        }

        return ['error' => $error];
    }
}
