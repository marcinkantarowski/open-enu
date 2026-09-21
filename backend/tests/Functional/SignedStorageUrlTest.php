<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use OpenEnu\Kernel\Storage\StorageInterface;

/**
 * The one route where the URL *is* the credential.
 *
 * `/storage/{key}` is fetched by a browser that may not send cookies at all -
 * an `<img>` tag, a download manager - so there is no session to check. What
 * stands in for one is a signature covering an expiry, which means every
 * property below is the only thing between a stored file and the internet.
 */
final class SignedStorageUrlTest extends ApiTestCase
{
    protected function fixtures(): array
    {
        return [];
    }

    public function testASignedUrlServesTheFile(): void
    {
        $this->givenATenant();
        $url = $this->storeAndSign('report.txt', 'quarterly numbers');

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSame('quarterly numbers', $this->streamedContent());
    }

    public function testAnUnsignedUrlIsRefused(): void
    {
        $this->givenATenant();
        $url = $this->storeAndSign('report.txt', 'quarterly numbers');

        $this->client->request('GET', parse_url($url, \PHP_URL_PATH) ?: '/storage/nothing');

        // 403, and identical to the answer for a tampered one: this endpoint
        // must not become a way to ask whether a key exists.
        self::assertResponseStatusCodeSame(403);
    }

    public function testATamperedKeyIsRefused(): void
    {
        $this->givenATenant();
        $url = $this->storeAndSign('report.txt', 'quarterly numbers');

        // The signature covers the whole URL, so altering the key after signing
        // invalidates it - which is what stops one signed link from being
        // edited into a link to somebody else's file. The filename is not in
        // the key (storage generates it), so the last character of the path is
        // mutated rather than a recognisable word.
        [$path, $query] = explode('?', $url, 2);
        $this->client->request('GET', substr($path, 0, -1) . 'z?' . $query);

        self::assertResponseStatusCodeSame(403);
    }

    public function testATraversalKeyCannotEscapeTheStorageRoot(): void
    {
        $this->givenATenant();

        $this->client->request('GET', '/storage/../../../etc/passwd');

        // Refused before the backend is ever asked: an unsigned request is
        // rejected on the signature, so `../` never reaches a path.
        self::assertResponseStatusCodeSame(403);
        // And nothing was served as a file, which is the part that would matter
        // if the signature check were ever moved or removed.
        self::assertFalse($this->client->getResponse()->headers->has('Content-Disposition'));
    }

    /**
     * The body of a StreamedResponse.
     *
     * `getResponse()->getContent()` returns `false` for one - a file is streamed
     * rather than assembled in memory - and sending it a second time yields
     * nothing, because the stream has already been consumed. The browser's own
     * captured response is the only copy, and a test that asserted on `false`
     * would pass for the wrong reason.
     */
    private function streamedContent(): string
    {
        return (string) $this->client->getInternalResponse()->getContent();
    }

    /** Writes a file through the real storage backend and returns a signed URL for it. */
    private function storeAndSign(string $filename, string $contents): string
    {
        $storage = self::getContainer()->get(StorageInterface::class);
        \assert($storage instanceof StorageInterface);

        // Through the backend, not by writing a file: the key is generated and
        // tenant-prefixed there, and a hand-made one would test this file's
        // idea of the layout rather than the backend's.
        $key = $storage->write('test', $filename, $contents, 'text/plain');
        $url = $storage->temporaryUrl($key);

        $path = parse_url($url, \PHP_URL_PATH);
        $query = parse_url($url, \PHP_URL_QUERY);
        \assert(\is_string($path));

        return $path . ($query !== null ? '?' . $query : '');
    }
}
