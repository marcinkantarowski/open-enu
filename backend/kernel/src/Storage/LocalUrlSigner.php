<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Storage;

use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Signs a download URL for the local filesystem backend.
 *
 * S3 signs natively; a local filesystem cannot, so the application signs a route
 * instead. The signature covers the storage key and an expiry, so the URL cannot
 * be edited to reach another file and stops working on its own.
 *
 * This is dev-and-small-deployment infrastructure: it streams bytes through PHP.
 * The S3 signer (Phase 8) hands the object straight to the client.
 */
final readonly class LocalUrlSigner implements UrlSignerInterface
{
    public function __construct(
        private RouterInterface $router,
        private UriSigner $signer,
    ) {
    }

    public function sign(string $key, int $ttlSeconds): string
    {
        $url = $this->router->generate(
            'kernel_storage_download',
            ['key' => $key],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        // Symfony's UriSigner embeds the expiry in the signature, so a tampered
        // expiry invalidates the whole URL rather than extending it.
        return $this->signer->sign($url, new \DateTimeImmutable('@' . (time() + $ttlSeconds)));
    }
}
