<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Http\Listener;

use OpenEnu\Kernel\Dto\ErrorResponse;
use OpenEnu\Kernel\Http\Exception\ConflictException;
use OpenEnu\Kernel\Http\RequestId;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Converts any uncaught exception into the one API error shape.
 *
 * Two properties matter more than the formatting:
 *
 *  - **It never leaks internals in production.** A 500 returns a generic message
 *    and the request id; the real message and stack trace go to the log, where
 *    the request id links them. In dev the message is included, because the
 *    alternative is reading container logs for every typo.
 *
 *  - **It only touches API responses.** Non-JSON routes fall through to
 *    Symfony's own handling, so the profiler and its stack traces keep working.
 */
final readonly class ApiExceptionListener
{
    public function __construct(private bool $debug)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        // Only take over responses that would be JSON anyway. A browser hitting
        // a broken page should still get Symfony's error page.
        if (!$this->wantsJson($request)) {
            return;
        }

        $throwable = $event->getThrowable();
        $requestId = RequestId::fromRequest($request);

        $error = match (true) {
            // Both versions reach the client, so a conflict bar can offer a real
            // choice instead of "reload and lose your work".
            $throwable instanceof ConflictException => new ErrorResponse(
                code: 'conflict',
                message: $throwable->getMessage(),
                status: 409,
                details: $throwable->body->jsonSerialize(),
                requestId: $requestId,
            ),
            $throwable instanceof ValidationFailedException => $this->fromValidation($throwable, $requestId),
            $throwable instanceof HttpExceptionInterface => new ErrorResponse(
                code: $this->codeForStatus($throwable->getStatusCode()),
                message: $throwable->getMessage() !== '' ? $throwable->getMessage() : $this->messageForStatus($throwable->getStatusCode()),
                status: $throwable->getStatusCode(),
                requestId: $requestId,
            ),
            default => new ErrorResponse(
                code: 'internal_error',
                // The message of an unexpected exception can carry anything -
                // a DSN, a file path, a fragment of a query. It is logged, not
                // returned, unless we are in debug.
                message: $this->debug ? $throwable->getMessage() : 'An unexpected error occurred.',
                status: 500,
                details: $this->debug ? [
                    'exception' => $throwable::class,
                    'file' => $throwable->getFile() . ':' . $throwable->getLine(),
                ] : [],
                requestId: $requestId,
            ),
        };

        $response = new JsonResponse($error->jsonSerialize(), $error->status);
        if ($throwable instanceof HttpExceptionInterface) {
            $response->headers->add($throwable->getHeaders());
        }

        $event->setResponse($response);
    }

    private function wantsJson(\Symfony\Component\HttpFoundation\Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api')
            || $request->getPreferredFormat() === 'json'
            || $request->isXmlHttpRequest();
    }

    private function fromValidation(ValidationFailedException $e, ?string $requestId): ErrorResponse
    {
        $violations = [];
        foreach ($e->getViolations() as $violation) {
            $violations[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        return new ErrorResponse(
            code: 'validation_failed',
            message: 'The submitted data is invalid.',
            status: 422,
            details: ['violations' => $violations],
            requestId: $requestId,
        );
    }

    private function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            422 => 'validation_failed',
            429 => 'rate_limited',
            503 => 'service_unavailable',
            default => $status >= 500 ? 'internal_error' : 'request_failed',
        };
    }

    private function messageForStatus(int $status): string
    {
        return match ($status) {
            401 => 'Authentication is required.',
            403 => 'You do not have permission to do that.',
            404 => 'The requested resource does not exist.',
            429 => 'Too many requests.',
            default => 'The request could not be completed.',
        };
    }
}
