<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Mcp;

use OpenEnu\Kernel\Module\ModuleRegistry;
use Symfony\Component\Routing\RouterInterface;

/**
 * The API, as a list of tools an MCP client can call.
 *
 * Generated, never hand-written (.ai/platform/PLAN.md §14): an endpoint added today is
 * callable by an agent today, and one deleted stops being offered. A curated
 * list would be a second place to remember, and the second place is always the
 * one that is wrong.
 *
 * Three sources, each contributing what only it knows:
 *
 *   openapi.json  which operations are PUBLISHED - the committed contract
 *                 (ADR-0012), so a route that exists but is not in the spec is
 *                 not offered to a client
 *   the router    the path, the method, and which segments are parameters
 *   `#[IsGranted]` the permission a key must hold, which is the difference
 *                 between a usable tool list and a list of things that 403
 *
 * Only operations guarded by a DECLARED module permission are offered, and that
 * single rule does all the filtering. An API key is a scoped tenant credential:
 * it cannot sign in, cannot refresh a browser session, and is refused by the
 * operator firewall on its `aud` claim (ADR-0007). Those endpoints exist and are
 * documented; offering them here would only produce an agent that spends its
 * turns discovering which of its tools are permanently 401.
 */
final readonly class ToolCatalogue
{
    public function __construct(
        private RouterInterface $router,
        private ModuleRegistry $modules,
        private string $specPath,
    ) {
    }

    /**
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, method: string, path: string}>
     */
    public function tools(): array
    {
        $published = $this->publishedOperations();
        $tools = [];

        foreach ($this->router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();
            $methods = $route->getMethods() ?: ['GET'];

            foreach ($methods as $method) {
                if (!isset($published[strtolower($method) . ' ' . $path])) {
                    continue;
                }

                $controller = $route->getDefault('_controller');
                $permission = \is_string($controller) ? $this->permissionFor($controller) : null;

                // No declared permission, no tool. See the class docblock.
                if ($permission === null || $this->modules->ownerOf($permission) === null) {
                    continue;
                }

                $tools[] = [
                    'name' => $name,
                    'description' => sprintf('%s %s - requires the "%s" permission.', $method, $path, $permission),
                    'inputSchema' => $this->schemaFor($path, $method),
                    'method' => $method,
                    'path' => $path,
                ];
            }
        }

        usort($tools, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $tools;
    }

    /** @return array<string, true> "get /api/projects" => true */
    private function publishedOperations(): array
    {
        if (!is_file($this->specPath)) {
            // Loud, not empty: an MCP server offering nothing looks exactly like
            // an API with no endpoints, and the operator would go looking in the
            // wrong place entirely.
            throw new \RuntimeException(sprintf('No OpenAPI spec at %s - run `make openapi`.', $this->specPath));
        }

        /** @var array{paths?: array<string, array<string, mixed>>} $spec */
        $spec = json_decode((string) file_get_contents($this->specPath), true, 512, \JSON_THROW_ON_ERROR);

        $operations = [];

        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $operations[$method . ' ' . $path] = true;
            }
        }

        return $operations;
    }

    /** The permission named by `#[IsGranted]` on the action, if it names one. */
    private function permissionFor(string $controller): ?string
    {
        if (!str_contains($controller, '::')) {
            return null;
        }

        [$class, $method] = explode('::', $controller, 2);

        if (!class_exists($class)) {
            return null;
        }

        foreach ((new \ReflectionMethod($class, $method))->getAttributes() as $attribute) {
            if (!str_ends_with($attribute->getName(), '\\IsGranted')) {
                continue;
            }

            $argument = $attribute->getArguments()[0] ?? $attribute->getArguments()['attribute'] ?? null;

            if (\is_string($argument)) {
                return $argument;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed> a JSON Schema for the tool's arguments
     *
     * The body is deliberately free-form. The committed spec records which
     * operations exist but not yet what they accept, and inventing a schema here
     * would mean a client validating against this file's guess rather than the
     * server's actual rules - which is worse than no schema, because it fails in
     * the client where the reason is invisible.
     */
    private function schemaFor(string $path, string $method): array
    {
        $properties = [];
        $required = [];

        preg_match_all('/\{(\w+)\}/', $path, $matches);

        foreach ($matches[1] as $parameter) {
            $properties[$parameter] = ['type' => 'string', 'description' => 'Path parameter.'];
            $required[] = $parameter;
        }

        if (\in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $properties['body'] = ['type' => 'object', 'description' => 'JSON request body.'];
        } else {
            $properties['query'] = ['type' => 'object', 'description' => 'Query-string parameters.'];
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }
}
