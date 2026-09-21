<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Console;

use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Setup\SetupRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs every module's tenant setup, in dependency order.
 *
 * Two modes because they are genuinely different operations:
 *   --create  the rows a tenant cannot function without
 *   default   sample data, for demos and development
 *
 * Merging them is how demo records end up in a customer's account.
 */
#[AsCommand(name: 'app:tenant:seed', description: "Run every module's tenant setup hooks")]
final class TenantSeedCommand extends Command
{
    public function __construct(
        private readonly SetupRunner $runner,
        private readonly ScopeContext $scope,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant id (UUID)')
            ->addOption('create', null, InputOption::VALUE_NONE, 'Run onTenantCreated instead of seedExamples');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenant = (string) $input->getOption('tenant');

        if ($tenant === '') {
            $io->error('--tenant is required: setup hooks write tenant-scoped rows, so they need to know whose.');

            return Command::INVALID;
        }

        // Enter the tenant's scope BEFORE running anything.
        //
        // Without it every scoped read in a setup hook returns nothing - the
        // filter is fail-closed by design (ADR-0004) - so each hook's "have I
        // already done this?" check answers no, and re-seeding silently
        // duplicates every example row instead of being idempotent.
        $this->scope->enter([ScopeContext::TENANT => $tenant]);

        $providers = $this->runner->providers();

        if ($providers === []) {
            $io->warning('No module implements TenantSetupInterface yet.');

            return Command::SUCCESS;
        }

        if ($input->getOption('create') === true) {
            $this->runner->onTenantCreated($tenant);
            $io->success(sprintf('Initialised tenant "%s" across %d module(s).', $tenant, \count($providers)));
        } else {
            $this->runner->seedExamples($tenant);
            $io->success(sprintf('Seeded tenant "%s" across %d module(s).', $tenant, \count($providers)));
        }

        foreach ($providers as $provider) {
            $io->writeln('  ' . $provider);
        }

        return Command::SUCCESS;
    }
}
