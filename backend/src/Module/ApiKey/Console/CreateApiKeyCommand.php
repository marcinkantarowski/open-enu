<?php

declare(strict_types=1);

namespace App\Module\ApiKey\Console;

use App\Module\ApiKey\Command\CreateApiKey;
use App\Module\Tenant\Contract\TenantReaderInterface;
use OpenEnu\Kernel\Command\CommandBusInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A scoped key, from the command line.
 *
 * The API can already mint one, but only for a caller holding `api_key.manage`
 * - which means a browser session, which means a person. Setting a machine up
 * (an MCP client, a CI job, a scheduled import) would otherwise start with
 * somebody logging in and pasting a secret out of a web page.
 *
 * Through the bus like every other write, so the key appears in the audit trail
 * with `app:apikey:create` as the actor rather than materialising unexplained.
 */
#[AsCommand(name: 'app:apikey:create', description: 'Mint a scoped API key for a tenant')]
final class CreateApiKeyCommand extends Command
{
    public function __construct(
        private readonly CommandBusInterface $commands,
        private readonly TenantReaderInterface $tenants,
        private readonly ScopeContext $scope,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant id or slug')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'What this key is for', 'cli')
            ->addOption('permission', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Repeatable; a key with none grants nothing')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Expire after N days');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenantId = $this->resolve((string) $input->getOption('tenant'));

        if ($tenantId === null) {
            $io->error('No such tenant. Pass --tenant=<id|slug>; `make console CMD=app:module:list` will not help, but the manager console lists them.');

            return Command::INVALID;
        }

        /** @var list<string> $permissions */
        $permissions = $input->getOption('permission');

        if ($permissions === []) {
            // Refused rather than defaulted. A key created with "everything"
            // because nobody said otherwise is the credential that shows up in
            // an incident report.
            $io->error('At least one --permission is required - a key with none can do nothing at all.');

            return Command::INVALID;
        }

        $days = $input->getOption('days');

        // The handler reads the tenant from the ambient scope: a key belongs to
        // exactly one workspace, and there is no session here to establish that.
        $this->scope->enter([ScopeContext::TENANT => $tenantId]);

        /** @var array<string, mixed> $key */
        $key = $this->commands->dispatch(new CreateApiKey(
            name: (string) $input->getOption('name'),
            permissions: $permissions,
            expiresInDays: \is_string($days) ? (int) $days : null,
        ));

        $io->success('Key created.');
        $io->writeln((string) $key['secret']);
        $io->comment('Stored hashed - this is the only time it exists in clear.');

        return Command::SUCCESS;
    }

    /**
     * Accepts an id or a slug.
     *
     * The slug is resolved by scanning, because `TenantReaderInterface` does not
     * offer a lookup by one and widening another module's contract for a
     * convenience in a bootstrap command is the wrong trade (ADR-0002). A
     * deployment with enough tenants for this to matter has an operator console.
     */
    private function resolve(string $tenant): ?string
    {
        if ($tenant === '') {
            return null;
        }

        if ($this->tenants->exists($tenant)) {
            return $tenant;
        }

        return $this->scope->runUnscoped(
            'resolving a tenant slug from the command line, before any scope exists',
            function () use ($tenant): ?string {
                foreach ($this->tenants->page(0, 500) as $row) {
                    if (($row['slug'] ?? null) === $tenant) {
                        return (string) $row['id'];
                    }
                }

                return null;
            },
        );
    }
}
