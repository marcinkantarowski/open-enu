<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Console;

use OpenEnu\Kernel\Attribute\NoTestRequired;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\RouterInterface;

/**
 * Compares the routes that exist with the routes the suite actually hit.
 *
 * A command rather than a test, because it has to run AFTER the functional
 * suite has finished writing its trace - and a test that depends on another
 * suite having already run is a test that passes or fails by ordering.
 *
 * Exemptions carry a written reason via `#[NoTestRequired]` and are reported as
 * exemptions, never counted as coverage.
 */
#[AsCommand(name: 'app:route:coverage', description: 'Fail if any route was never exercised by the test suite')]
final class RouteCoverageCommand extends Command
{
    public function __construct(private readonly RouterInterface $router)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('trace', InputArgument::REQUIRED, 'File written by RouteTraceListener');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $trace = (string) $input->getArgument('trace');

        if (!is_file($trace)) {
            $io->error(sprintf('No trace at %s - run the functional suite with ROUTE_TRACE set first.', $trace));

            return Command::FAILURE;
        }

        $hit = array_flip(array_filter(array_map(trim(...), file($trace) ?: [])));

        $untested = [];
        $exempt = [];

        foreach ($this->router->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');

            // Profiler and asset routes belong to bundles, not to this
            // application, and nobody here can write a test for them.
            if (!\is_string($controller) || !str_contains($controller, '::')) {
                continue;
            }
            if (!str_starts_with($controller, 'App\\') && !str_starts_with($controller, 'OpenEnu\\')) {
                continue;
            }

            if (isset($hit[$name])) {
                continue;
            }

            $reason = $this->exemption($controller);
            if ($reason !== null) {
                $exempt[$name] = $reason;

                continue;
            }

            $untested[$name] = sprintf('%s %s', implode('|', $route->getMethods() ?: ['ANY']), $route->getPath());
        }

        foreach ($exempt as $name => $reason) {
            $io->writeln(sprintf('  <fg=yellow>·</> %-34s exempt - %s', $name, $reason));
        }

        if ($untested === []) {
            $io->success(sprintf(
                '%d route(s) exercised, %d exempt.',
                \count($hit),
                \count($exempt),
            ));

            return Command::SUCCESS;
        }

        $io->error(sprintf('%d route(s) were never exercised by the suite:', \count($untested)));
        foreach ($untested as $name => $signature) {
            $io->writeln(sprintf('  <fg=red>✗</> %-34s %s', $name, $signature));
        }
        $io->writeln('');
        $io->writeln('  Write a functional test for it, or - if it genuinely cannot have one -');
        $io->writeln('  mark the action #[NoTestRequired(reason: \'…\')] and say why.');

        return Command::FAILURE;
    }

    private function exemption(string $controller): ?string
    {
        [$class, $method] = explode('::', $controller, 2);

        if (!class_exists($class)) {
            return null;
        }

        try {
            $attributes = (new \ReflectionMethod($class, $method))->getAttributes(NoTestRequired::class);
        } catch (\ReflectionException) {
            return null;
        }

        return $attributes === [] ? null : $attributes[0]->newInstance()->reason;
    }
}
