<?php

declare(strict_types=1);

namespace App\Module\Attachment\Handler;

use App\Module\Attachment\Command\UploadAttachment;
use App\Module\Attachment\Entity\Attachment;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Storage\StorageInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UploadAttachmentHandler
{
    public function __construct(
        private StorageInterface $storage,
        private EntityManagerInterface $em,
        private ScopeContext $scope,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(UploadAttachment $command): array
    {
        $tenantId = $this->scope->tenantId()
            ?? throw new BadRequestHttpException('No tenant in scope.');

        // The storage layer generates the key and prefixes it with the tenant,
        // so a user-supplied filename can never become a path.
        $key = $this->storage->write('attachment', $command->filename, $command->contents, $command->contentType);

        $attachment = new Attachment(
            tenantId: $tenantId,
            storageKey: $key,
            filename: $command->filename,
            contentType: $command->contentType,
            size: $command->size,
        );

        if ($command->ownerType !== null && $command->ownerId !== null) {
            $attachment->attachTo($command->ownerType, $command->ownerId);
        }

        $this->em->persist($attachment);
        $this->em->flush();

        return [...$attachment->toArray(), 'url' => $this->storage->temporaryUrl($key)];
    }
}
