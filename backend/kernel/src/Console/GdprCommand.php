<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Console;

use OpenEnu\Kernel\Gdpr\GdprWalker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Export or erase everything held about one person.
 *
 * Export is safe and prints JSON. Erasure is irreversible, so it requires
 * `--force` and a typed confirmation: a subject-access deletion carried out
 * against the wrong id cannot be undone by restoring a backup without also
 * un-deleting everyone else's.
 */
#[AsCommand(name: 'app:gdpr', description: 'Export or erase all data held about a user')]
final class GdprCommand extends Command
{
    public function __construct(private readonly GdprWalker $walker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'export or erase')
            ->addArgument('userId', InputArgument::REQUIRED, 'the subject')
            ->addOption('force', null, InputOption::VALUE_NONE, 'required for erase')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'write the export to a file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        $userId = (string) $input->getArgument('userId');

        return match ($action) {
            'export' => $this->export($io, $userId, $input->getOption('out')),
            'erase' => $this->erase($io, $input, $userId),
            default => $this->unknown($io, $action),
        };
    }

    private function export(SymfonyStyle $io, string $userId, mixed $out): int
    {
        $bundle = $this->walker->export($userId);
        $json = json_encode($bundle, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $io->error('Could not serialise the export: ' . json_last_error_msg());

            return Command::FAILURE;
        }

        if (\is_string($out)) {
            file_put_contents($out, $json);
            $io->success(sprintf('Exported %d section(s) to %s', \count($bundle), $out));
        } else {
            $io->writeln($json);
        }

        return Command::SUCCESS;
    }

    private function erase(SymfonyStyle $io, InputInterface $input, string $userId): int
    {
        if ($input->getOption('force') !== true) {
            $io->error('Erasure is irreversible. Re-run with --force.');

            return Command::FAILURE;
        }

        if ($input->isInteractive() && !$io->confirm(sprintf('Permanently erase all data for "%s"?', $userId), false)) {
            $io->writeln('Aborted.');

            return Command::FAILURE;
        }

        $affected = $this->walker->erase($userId);

        $io->success(sprintf('Erased %d row(s) across %d module(s)', array_sum($affected), \count($affected)));
        foreach ($affected as $module => $rows) {
            $io->writeln(sprintf('  %-60s %d', $module, $rows));
        }

        return Command::SUCCESS;
    }

    private function unknown(SymfonyStyle $io, string $action): int
    {
        $io->error(sprintf('Unknown action "%s". Use "export" or "erase".', $action));

        return Command::INVALID;
    }
}
