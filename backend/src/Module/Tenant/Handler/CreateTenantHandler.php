<?php

declare(strict_types=1);

namespace App\Module\Tenant\Handler;

use App\Module\Tenant\Command\CreateTenant;
use App\Module\Tenant\Entity\Tenant;
use App\Module\Tenant\Event\TenantCreated;
use App\Module\Tenant\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Setup\SetupRunner;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class CreateTenantHandler
{
    public function __construct(
        private TenantRepository $tenants,
        private EntityManagerInterface $em,
        private SetupRunner $setup,
        private ScopeContext $scope,
        private MessageBusInterface $events,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(CreateTenant $command): array
    {
        // The slug appears in storage keys and URLs, so a collision is not
        // recoverable by renaming later.
        if ($this->tenants->bySlug($command->slug) !== null) {
            throw new ConflictHttpException(sprintf('The slug "%s" is taken.', $command->slug));
        }

        $tenant = new Tenant($command->slug, $command->name);
        $tenant->setDefaultLocale($command->defaultLocale);

        $this->em->persist($tenant);
        $this->em->flush();

        $tenantId = (string) $tenant->id();

        // Every module's defaults are created INSIDE the new tenant's scope, so
        // a setup provider writing a scoped entity does not have to remember to
        // stamp it - and cannot get it wrong.
        $previous = $this->scope->all();
        $this->scope->enter([ScopeContext::TENANT => $tenantId]);

        try {
            $this->setup->onTenantCreated($tenantId);
        } finally {
            $this->scope->enter($previous);
        }

        $this->events->dispatch(new TenantCreated($tenantId, $tenant->slug(), $tenant->name()));

        return $tenant->toArray();
    }
}
