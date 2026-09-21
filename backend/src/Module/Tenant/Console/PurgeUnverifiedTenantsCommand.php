<?php

declare(strict_types=1);

namespace App\Module\Tenant\Console;

use App\Module\Tenant\Command\DeleteTenant;
use App\Module\Tenant\Repository\TenantRepository;
use OpenEnu\Kernel\Command\CommandBusInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes workspaces nobody ever confirmed.
 *
 * Signup creates a tenant before the address is proved, which is what makes an
 * open signup form a tenant-spam endpoint. Verification activates it; this is
 * what removes the ones that never were.
 *
 * Scheduled daily (`docker/compose.dev.yml`, service `scheduler`). Run by hand
 * with `--dry-run` first - it deletes, and the tenant cascade takes its data
 * with it.
 */
#[AsCommand(name: 'app:tenant:purge-unverified', description: 'Delete tenants still pending after a grace period')]
final class PurgeUnverifiedTenantsCommand extends Command
{
    /** Long enough that a person who signed up on Friday still has a workspace on Monday. */
    private const int DEFAULT_GRACE_DAYS = 7;

    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly CommandBusInterface $commands,
        private readonly ScopeContext $scope,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Grace period', (string) self::DEFAULT_GRACE_DAYS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would go, delete nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getOption('days'));
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $days));
        $dryRun = $input->getOption('dry-run') === true;

        // Across every tenant by definition: this task IS the one that looks at
        // all of them, so the exception is stated rather than ambient (ADR-0004).
        $stale = $this->scope->runUnscoped(
            'purging tenants that were never verified, across all of them',
            fn (): array => $this->tenants->pendingSince($cutoff),
        );

        if ($stale === []) {
            $io->success(sprintf('Nothing pending since %s.', $cutoff->format('Y-m-d')));

            return Command::SUCCESS;
        }

        foreach ($stale as $tenant) {
            $io->writeln(sprintf('  %s %s (%s)', $dryRun ? '·' : '✗', $tenant->slug(), $tenant->id()));

            if (!$dryRun) {
                // Through the bus, so the deletion is audited and the cascade
                // listeners run - a direct remove() would orphan every module's
                // rows for that tenant.
                $this->commands->dispatch(new DeleteTenant((string) $tenant->id()));
            }
        }

        $io->success(sprintf(
            '%d unverified tenant(s) %s.',
            \count($stale),
            $dryRun ? 'would be deleted' : 'deleted',
        ));

        return Command::SUCCESS;
    }
}
