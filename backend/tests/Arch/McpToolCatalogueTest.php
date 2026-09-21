<?php

declare(strict_types=1);

namespace App\Tests\Arch;

use OpenEnu\Kernel\Mcp\ToolCatalogue;
use OpenEnu\Kernel\Module\ModuleRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * What an agent is offered, and what it is deliberately not.
 *
 * The tool list is generated, so it cannot go stale - but it can go WRONG, and
 * the two ways it can go wrong are both security-shaped: offering something a
 * scoped key can never call (an agent burning its turns on guaranteed 401s), or
 * offering something it should never call at all.
 *
 * Against the real router and the committed spec, with nothing mocked: the rule
 * under test is "these three sources agree", and a mock of any of them would be
 * this file agreeing with itself.
 */
final class McpToolCatalogueTest extends KernelTestCase
{
    /** @return list<array{name: string, description: string, inputSchema: array<string, mixed>, method: string, path: string}> */
    private function tools(): array
    {
        if (self::$kernel === null) {
            self::bootKernel();
        }

        $catalogue = self::getContainer()->get(ToolCatalogue::class);
        \assert($catalogue instanceof ToolCatalogue);

        return $catalogue->tools();
    }

    public function testEveryToolNamesARealRoute(): void
    {
        $router = self::getContainer()->get('router');
        \assert($router instanceof RouterInterface);
        $routes = $router->getRouteCollection();

        foreach ($this->tools() as $tool) {
            $route = $routes->get($tool['name']);

            self::assertNotNull($route, sprintf('Tool "%s" names no route.', $tool['name']));
            self::assertSame($route->getPath(), $tool['path']);
        }
    }

    public function testEveryToolRequiresAPermissionSomeModuleDeclares(): void
    {
        $modules = self::getContainer()->get(ModuleRegistry::class);
        \assert($modules instanceof ModuleRegistry);

        foreach ($this->tools() as $tool) {
            // The description is what the model reads before choosing; if it
            // does not say what the key must hold, the model cannot tell a
            // missing grant from a broken endpoint.
            self::assertMatchesRegularExpression('/requires the "([a-z_]+(\.[a-z_]+)+)" permission/', $tool['description']);

            preg_match('/requires the "([^"]+)"/', $tool['description'], $m);
            self::assertNotNull($modules->ownerOf($m[1]), sprintf('"%s" is not declared by any module.', $m[1]));
        }
    }

    public function testNothingAnApiKeyCanNeverCallIsOffered(): void
    {
        foreach ($this->tools() as $tool) {
            // The operator realm refuses an api_key token on its `aud` claim
            // (ADR-0007), and the auth endpoints establish browser sessions a
            // key has no use for. Both are permanently unreachable here.
            self::assertStringStartsNotWith('/api/manager', $tool['path']);
            self::assertStringStartsNotWith('/api/auth', $tool['path']);
        }
    }

    public function testTheReferenceModulesWriteEndpointIsOfferedWithABody(): void
    {
        $byName = [];
        foreach ($this->tools() as $tool) {
            $byName[$tool['name']] = $tool;
        }

        // If this one ever disappears, the generation has broken: it is an
        // ordinary permission-guarded POST, which is the shape of most of them.
        self::assertArrayHasKey('example_project_create', $byName);
        self::assertArrayHasKey('body', $byName['example_project_create']['inputSchema']['properties']);

        // And a path parameter becomes a required argument, or the client has
        // no way to say WHICH record it means.
        self::assertSame(['id'], $byName['example_project_show']['inputSchema']['required']);
    }
}
