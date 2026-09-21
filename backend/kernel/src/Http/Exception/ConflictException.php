<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Http\Exception;

use OpenEnu\Kernel\Dto\ConflictBody;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Someone else changed the record first.
 *
 * Carries the structured body so the API error handler can return both versions
 * without the caller having to build the payload at every write site.
 */
final class ConflictException extends HttpException
{
    public function __construct(public readonly ConflictBody $body, ?\Throwable $previous = null)
    {
        parent::__construct(409, 'This record was changed by someone else.', $previous);
    }
}
