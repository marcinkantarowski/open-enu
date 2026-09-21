<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Http\Controller;

use OpenEnu\Kernel\Storage\StorageInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves a file for a signed, unexpired URL.
 *
 * Authorization is the signature, not the session: the URL is handed to a
 * browser that may fetch it without cookies (an <img> tag, a download manager).
 * That is why the signature covers an expiry - the URL IS the credential, so it
 * must stop working.
 *
 * The key is never trusted as a path: it is passed to the storage backend, which
 * resolves it within its own root. `../` in a key cannot escape because the
 * signature would not match anyway.
 */
final readonly class StorageController
{
    public function __construct(
        private StorageInterface $storage,
        private UriSigner $signer,
    ) {
    }

    #[Route('/storage/{key}', name: 'kernel_storage_download', requirements: ['key' => '.+'], methods: ['GET'])]
    public function download(Request $request, #[MapQueryParameter] ?string $disposition = null): Response
    {
        if (!$this->signer->checkRequest($request)) {
            // Indistinguishable from "no such file" on purpose: a different
            // response for an expired signature tells an attacker the key exists.
            throw new AccessDeniedHttpException('This link is invalid or has expired.');
        }

        $key = (string) $request->attributes->get('key');

        if (!$this->storage->exists($key)) {
            throw new NotFoundHttpException();
        }

        $stream = $this->storage->readStream($key);

        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
        });

        $response->headers->set('Content-Length', (string) $this->storage->size($key));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Never inline by default: an uploaded SVG or HTML file rendered inline
        // executes as the application's own origin.
        $response->headers->set(
            'Content-Disposition',
            $disposition === 'inline' ? 'inline' : 'attachment',
        );

        return $response;
    }
}
