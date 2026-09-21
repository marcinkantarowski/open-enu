<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Console;

use OpenEnu\Kernel\Kernel;
use OpenEnu\Kernel\Mcp\McpServer;
use OpenEnu\Kernel\Mcp\ToolCatalogue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Serves the API to an MCP client over stdio.
 *
 * The credential is an argument, never a default: a server that fell back to
 * some ambient key would give an agent whatever access the operator happened to
 * have, which is the opposite of the point. Create a scoped key first:
 *
 *     make console CMD="app:apikey:create --name=agent --permission=example.view"
 *
 * Then point a client at it:
 *
 *     {"command": "docker", "args": ["compose", "exec", "-T", "api",
 *      "php", "bin/console", "app:mcp:serve", "--key=sk_…"]}
 *
 * `--list` prints the tool list and exits, which is the fast way to see what a
 * client would be offered without speaking JSON-RPC at it.
 */
#[AsCommand(name: 'app:mcp:serve', description: 'Serve the API to an MCP client over stdio, authenticating with an API key')]
final class McpServeCommand extends Command
{
    public function __construct(
        private readonly ToolCatalogue $catalogue,
        private readonly HttpClientInterface $http,
        private readonly string $apiUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'API key (or OPEN_ENU_MCP_KEY)')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'API base URL; defaults to this installation\'s')
            ->addOption('list', null, InputOption::VALUE_NONE, 'Print the tools and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('list') === true) {
            foreach ($this->catalogue->tools() as $tool) {
                $output->writeln(sprintf('%-34s %s', $tool['name'], $tool['description']));
            }

            return Command::SUCCESS;
        }

        $key = $input->getOption('key');
        $key = \is_string($key) && $key !== '' ? $key : (string) getenv('OPEN_ENU_MCP_KEY');

        if ($key === '') {
            // Refused rather than started unauthenticated. An MCP server that
            // runs without a credential is one every tool call fails through,
            // and the client reports that as "the API is broken".
            $output->writeln('<error>An API key is required: --key=sk_… or OPEN_ENU_MCP_KEY.</error>');

            return Command::INVALID;
        }

        $url = $input->getOption('url');

        $server = new McpServer(
            $this->catalogue,
            $this->http,
            \is_string($url) && $url !== '' ? $url : $this->apiUrl,
            $key,
            Kernel::VERSION,
        );

        $in = fopen('php://stdin', 'r');
        $out = fopen('php://stdout', 'w');
        \assert(\is_resource($in) && \is_resource($out));

        $server->serve($in, $out);

        return Command::SUCCESS;
    }
}
