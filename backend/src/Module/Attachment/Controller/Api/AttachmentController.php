<?php

declare(strict_types=1);

namespace App\Module\Attachment\Controller\Api;

use App\Module\Attachment\Command\UploadAttachment;
use App\Module\Attachment\Repository\AttachmentRepository;
use OpenEnu\Kernel\Command\CommandBusInterface;
use OpenEnu\Kernel\Storage\StorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Upload, and hand back an expiring link.
 *
 * The file never passes through this module's own filesystem code:
 * StorageInterface generates the key and prefixes it with the tenant. A module
 * writing files itself would have to remember that prefix at every call site,
 * and missing it once puts one tenant's upload where another can read it.
 */
final readonly class AttachmentController
{
    public function __construct(
        private StorageInterface $storage,
        private AttachmentRepository $attachments,
        private CommandBusInterface $commands,
        private int $maxUploadMb,
    ) {
    }

    #[Route('/api/attachments', name: 'attachment_upload', methods: ['POST'])]
    #[IsGranted('attachment.manage')]
    public function upload(Request $request): JsonResponse
    {
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('Expected a multipart upload in the "file" field.');
        }

        if ((int) $file->getSize() > $this->maxUploadMb * 1024 * 1024) {
            throw new BadRequestHttpException(sprintf('Files must be %d MB or smaller.', $this->maxUploadMb));
        }

        $owner = $request->request->all();

        return new JsonResponse(
            $this->commands->dispatch(new UploadAttachment(
                // Metadata only - the storage key is generated, so this never
                // becomes a path.
                filename: $file->getClientOriginalName(),
                contents: file_get_contents($file->getPathname()) ?: '',
                // The CLIENT-supplied content type is not trusted: a browser
                // sends whatever the uploader's OS guessed, and an attacker
                // sends whatever suits them. getMimeType() sniffs the bytes.
                contentType: $file->getMimeType() ?? 'application/octet-stream',
                size: (int) $file->getSize(),
                ownerType: \is_string($owner['ownerType'] ?? null) ? $owner['ownerType'] : null,
                ownerId: \is_string($owner['ownerId'] ?? null) ? $owner['ownerId'] : null,
            )),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/api/attachments/{id}', name: 'attachment_show', methods: ['GET'])]
    #[IsGranted('attachment.view')]
    public function show(string $id): JsonResponse
    {
        // Another tenant's attachment is not found rather than forbidden: the
        // scope filter removes it from the query entirely.
        $attachment = $this->attachments->get($id) ?? throw new NotFoundHttpException('No such attachment.');

        return new JsonResponse([
            ...$attachment->toArray(),
            // Freshly signed on every read, and short-lived: a storage URL that
            // works forever is a credential, and it ends up in a chat message.
            'url' => $this->storage->temporaryUrl($attachment->storageKey()),
        ]);
    }
}
