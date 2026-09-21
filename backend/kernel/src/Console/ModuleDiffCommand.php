<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Console;

use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Module\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generate a migration containing ONLY one module's tables.
 *
 * `doctrine:migrations:diff --namespace=…` decides where the file is written and
 * nothing else: the diff itself always compares the entire schema. So on a fresh
 * database, generating a migration for Tenant and then one for Identity produces
 * two files that each create *every* table - the second fails on apply, and if
 * it had not, removing one module would drop the other's tables.
 *
 * Per-module migrations only work with a filter as well, and the filter has to
 * be derived from the module's own entity metadata, which is what this does.
 */
#[AsCommand(name: 'app:module:diff', description: "Generate a migration for one module's tables only")]
final class ModuleDiffCommand extends Command
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('module', InputArgument::REQUIRED, 'Module name, e.g. Identity');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $module = (string) $input->getArgument('module');

        if (!$this->registry->has($module)) {
            $io->error(sprintf('No module "%s". Known: %s', $module, implode(', ', $this->registry->names())));

            return Command::INVALID;
        }

        $tables = $this->tablesOf($module);

        if ($tables === []) {
            $io->warning(sprintf('Module "%s" maps no entities - nothing to diff.', $module));

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Tables owned by %s: <info>%s</info>', $module, implode(', ', $tables)));

        $application = $this->getApplication();
        \assert($application !== null);

        return $application->find('doctrine:migrations:diff')->run(
            new ArrayInput([
                '--namespace' => 'App\\Module\\' . $module . '\\Migrations',
                // Anchored exact alternation: a module owning `tenant` must not
                // also capture `tenant_settings` from another module.
                '--filter-expression' => '/^(' . implode('|', array_map(preg_quote(...), $tables)) . ')$/',
                '--no-interaction' => true,
            ]),
            $output,
        );
    }

    /** @return list<string> */
    private function tablesOf(string $module): array
    {
        $prefix = 'App\\Module\\' . $module . '\\Entity\\';
        $tables = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if (str_starts_with($meta->getName(), $prefix)) {
                $tables[] = $meta->getTableName();

                // Join tables of many-to-many associations belong to the owning
                // side's module, and are invisible in the entity list.
                foreach ($meta->getAssociationMappings() as $association) {
                    if (isset($association['joinTable']['name'])) {
                        $tables[] = (string) $association['joinTable']['name'];
                    }
                }
            }
        }

        return array_values(array_unique($tables));
    }
}
