<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Console;

use OpenEnu\Kernel\Module\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\RouterInterface;

/**
 * Prints what discovery actually resolved.
 *
 * Auto-discovery is convenient right up to the moment it is wrong, and then it
 * is opaque - a module that silently failed to load looks identical to one that
 * was never written. This command is the answer to "is my module actually
 * loaded, and what did it bring?".
 */
#[AsCommand(name: 'app:module:list', description: 'List discovered modules and what each contributes')]
final class ModuleListCommand extends Command
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly RouterInterface $router,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $modules = $this->registry->all();

        if ($modules === []) {
            $io->warning('No modules discovered under src/Module/.');
            $io->writeln('Create one with: <info>make module NAME=Billing</info>');

            return Command::SUCCESS;
        }

        $routesByModule = $this->routesByModule();

        $rows = [];
        foreach ($modules as $name => $info) {
            $path = $info['path'];
            $rows[] = [
                $name,
                $info['depends'] === [] ? '–' : implode(', ', $info['depends']),
                $routesByModule[$name] ?? 0,
                $this->countPhp($path . '/Entity'),
                $this->countPhp($path . '/Migrations'),
                \count(array_filter($this->registry->permissions(), static fn (string $m): bool => $m === $name)),
            ];
        }

        $io->title(sprintf('%d module%s discovered', \count($modules), \count($modules) === 1 ? '' : 's'));
        $io->table(['Module', 'Depends on', 'Routes', 'Entities', 'Migrations', 'Permissions'], $rows);

        if ($output->isVerbose()) {
            $io->section('Permissions');
            foreach ($this->registry->permissions() as $permission => $owner) {
                $io->writeln(sprintf('  <info>%-40s</info> %s', $permission, $owner));
            }
        }

        $io->comment('Modules are listed in dependency order. Add <info>-v</info> for permissions.');

        return Command::SUCCESS;
    }

    /** @return array<string, int> */
    private function routesByModule(): array
    {
        $counts = [];
        foreach ($this->router->getRouteCollection() as $route) {
            $controller = $route->getDefault('_controller');
            if (!\is_string($controller)) {
                continue;
            }
            if (preg_match('/^App\\\\Module\\\\([A-Za-z0-9_]+)\\\\/', $controller, $m) === 1) {
                $counts[$m[1]] = ($counts[$m[1]] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function countPhp(string $dir): int
    {
        return is_dir($dir) ? \count(glob($dir . '/*.php') ?: []) : 0;
    }
}
