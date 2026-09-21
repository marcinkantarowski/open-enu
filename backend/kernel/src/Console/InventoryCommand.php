<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Console;

use OpenEnu\Kernel\Inventory\DuplicateNameHint;
use OpenEnu\Kernel\Inventory\InventoryBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the inventory as JSON, on stdout, and nothing else.
 *
 * Stdout rather than a file, because the API container only has `backend/`
 * mounted - `.ai/` lives a directory above it. Writing and comparing are the
 * host's job (`scripts/dev/inventory.sh`), which also keeps this command usable
 * in a pipe.
 *
 * The similarity hints go to stderr for the same reason: they are advisory
 * commentary, and mixing them into the document would corrupt it.
 */
#[AsCommand(name: 'app:inventory', description: 'Print the module inventory as JSON - what already exists')]
final class InventoryCommand extends Command
{
    public function __construct(
        private readonly InventoryBuilder $builder,
        private readonly DuplicateNameHint $hint,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inventory = $this->builder->build();

        $output->write(json_encode(
            $inventory,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        ) . "\n", false, OutputInterface::OUTPUT_RAW);

        $pairs = $this->hint->pairs($inventory);

        if ($pairs !== []) {
            // Advisory, permanently: see DuplicateNameHint for why this can
            // never be blocking.
            $io = new SymfonyStyle($input, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
            $io->section('Names close enough to be worth a look');
            $io->listing($pairs);
            $io->comment('A hint, not a finding - most of these are unrelated.');
        }

        return Command::SUCCESS;
    }
}
