<?php

declare(strict_types=1);

namespace App\Module\Attachment\Tests\Functional;

use App\Module\Attachment\Entity\Attachment;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Upload and read back, through a real multipart request.
 *
 * Multipart rather than a direct call to the command: the parts this endpoint
 * gets wrong are all in the transport - the field name, the sniffed content
 * type, and the size limit - and none of them exist below the HTTP layer.
 */
final class AttachmentApiTest extends ApiTestCase
{
    protected function fixtures(): array
    {
        return [Attachment::class];
    }

    public function testAnUploadIsStoredAndReadableWithATemporaryUrl(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->upload('notes.txt', 'the quick brown fox', ['ownerType' => 'example_project', 'ownerId' => 'abc']);

        self::assertResponseStatusCodeSame(201);
        $created = $this->json();
        self::assertSame('notes.txt', $created['filename']);
        self::assertSame(19, $created['size']);
        // The owner travels as metadata, not as an association: neither module
        // knows the other's schema (ADR-0002).
        self::assertSame('example_project', $created['ownerType']);

        $this->get('/api/attachments/' . $created['id']);

        self::assertResponseIsSuccessful();
        $shown = $this->json();
        // Signed fresh on every read. A storage URL that works forever is a
        // credential, and it ends up pasted into a chat message.
        self::assertNotEmpty($shown['url']);
        self::assertStringNotContainsString('notes.txt', $shown['url'], 'The key is generated, not the filename.');
    }

    public function testTheContentTypeIsSniffedRatherThanBelieved(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        // The client claims an image. A browser sends whatever the uploader's
        // OS guessed; an attacker sends whatever suits them.
        $this->upload('payload.png', "#!/bin/sh\necho hi\n", [], 'image/png');

        self::assertResponseStatusCodeSame(201);
        self::assertStringNotContainsString('image/png', (string) $this->json()['contentType']);
    }

    public function testARequestWithNoFilePartIsRefused(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->client->request('POST', '/api/attachments', ['ownerType' => 'example_project']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testAnotherTenantsAttachmentIsNotFoundRatherThanForbidden(): void
    {
        $this->givenATenant('alpha');
        $this->givenIAmSignedIn();
        $this->upload('alpha.txt', 'alpha only');
        $id = (string) $this->json()['id'];

        // A second workspace, with its own owner and its own session.
        $this->givenATenant('beta');
        $this->givenIAmSignedIn(email: 'beta-owner@example.test');

        $this->get('/api/attachments/' . $id);

        // 404, not 403: the scope filter removes the row from the query, so
        // there is nothing here to forbid - and a 403 would confirm it exists.
        self::assertResponseStatusCodeSame(404);
    }

    /** @param array<string, string> $fields */
    private function upload(string $filename, string $contents, array $fields = [], ?string $claimedType = null): void
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        \assert(\is_string($path));
        file_put_contents($path, $contents);

        $this->client->request('POST', '/api/attachments', $fields, [
            // `test: true` - the file was not really uploaded by PHP, and
            // without it Symfony refuses to treat it as an upload at all.
            'file' => new UploadedFile($path, $filename, $claimedType, null, true),
        ]);

        unlink($path);
    }
}
