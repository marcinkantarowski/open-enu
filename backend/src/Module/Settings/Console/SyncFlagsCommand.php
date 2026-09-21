<?php

declare(strict_types=1);

namespace App\Module\Settings\Console;

use App\Module\Settings\Service\FlagCatalogue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Makes every declared flag real.
 *
 * Run by `make builddev` and by every deploy, because a module that ships a new
 * `#[Flag]` is otherwise invisible: the operator console lists what is stored,
 * and an undeclared flag has nothing to list and nothing to switch.
 *
 * Idempotent, and it never overwrites a stored default - see FlagCatalogue.
 */
#[AsCommand(name: 'app:flags:sync', description: 'Reconcile declared feature flags with the database')]
final class SyncFlagsCommand extends Command
{
    public function __construct(private readonly FlagCatalogue $catalogue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('dry-run') === true) {
            foreach ($this->catalogue->declared() as $flag) {
                $io->writeln(sprintf('  %s  (%s, default %s)', $flag->identifier, $flag->type, json_encode($flag->default)));
            }

            return Command::SUCCESS;
        }

        $report = $this->catalogue->sync();

        foreach (['created', 'updated'] as $kind) {
            foreach ($report[$kind] as $identifier) {
                $io->writeln(sprintf('  %s %s', $kind === 'created' ? '+' : '~', $identifier));
            }
        }

        $io->success(sprintf(
            '%d flag(s): %d created, %d updated, %d unchanged.',
            \count($report['created']) + \count($report['updated']) + \count($report['unchanged']),
            \count($report['created']),
            \count($report['updated']),
            \count($report['unchanged']),
        ));

        return Command::SUCCESS;
    }
}
