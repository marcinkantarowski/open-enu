<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use OpenEnu\Kernel\Command\CommandBusInterface;
use OpenEnu\Kernel\Command\LoggingAuditLogger;
use OpenEnu\Kernel\Command\MessengerCommandBus;
use OpenEnu\Kernel\Command\NullActorProvider;
use OpenEnu\Kernel\Command\SnapshotCollector;
use OpenEnu\Kernel\Console\GdprCommand;
use OpenEnu\Kernel\Console\ModuleDiffCommand;
use OpenEnu\Kernel\Console\ModuleListCommand;
use OpenEnu\Kernel\Console\InventoryCommand;
use OpenEnu\Kernel\Console\McpServeCommand;
use OpenEnu\Kernel\Console\RouteCoverageCommand;
use OpenEnu\Kernel\Console\TenantSeedCommand;
use OpenEnu\Kernel\Contract\ActorProviderInterface;
use OpenEnu\Kernel\Contract\AuditLoggerInterface;
use OpenEnu\Kernel\Crypto\DerivedKeyProvider;
use OpenEnu\Kernel\Crypto\Encryptor;
use OpenEnu\Kernel\Crypto\KeyProviderInterface;
use OpenEnu\Kernel\Doctrine\OptimisticLock;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Flags\FlagsInterface;
use OpenEnu\Kernel\Flags\StaticFlags;
use OpenEnu\Kernel\Gdpr\GdprSubjectInterface;
use OpenEnu\Kernel\Gdpr\GdprWalker;
use OpenEnu\Kernel\Http\Controller\HealthController;
use OpenEnu\Kernel\Inventory\DuplicateNameHint;
use OpenEnu\Kernel\Inventory\InventoryBuilder;
use OpenEnu\Kernel\Mcp\ToolCatalogue;
use OpenEnu\Kernel\Event\ClientBroadcaster;
use OpenEnu\Kernel\Http\Controller\RealtimeTokenController;
use OpenEnu\Kernel\Http\Controller\StorageController;
use OpenEnu\Kernel\Http\Listener\ApiExceptionListener;
use OpenEnu\Kernel\Http\Listener\FlagListener;
use OpenEnu\Kernel\Http\Listener\RequestIdListener;
use OpenEnu\Kernel\I18n\AcceptLanguageProvider;
use OpenEnu\Kernel\I18n\LocalePreferenceProviderInterface;
use OpenEnu\Kernel\I18n\LocaleResolver;
use OpenEnu\Kernel\Logging\ContextProcessor;
use OpenEnu\Kernel\Logging\LogContextProviderInterface;
use OpenEnu\Kernel\Logging\RequestIdContextProvider;
use OpenEnu\Kernel\Messenger\RequestIdMiddleware;
use OpenEnu\Kernel\Messenger\TenantScopeMiddleware;
use OpenEnu\Kernel\Module\ModuleRegistry;
use OpenEnu\Kernel\Progress\LoggingProgressReporter;
use OpenEnu\Kernel\Progress\ProgressReporterInterface;
use OpenEnu\Kernel\Routing\ModuleRouteLoader;
use OpenEnu\Kernel\Security\AudienceListener;
use OpenEnu\Kernel\Security\ImpersonationListener;
use OpenEnu\Kernel\Http\Controller\SearchController;
use OpenEnu\Kernel\Http\RouteTraceListener;
use OpenEnu\Kernel\Notification\LoggingNotifier;
use OpenEnu\Kernel\Notification\NotifierInterface;
use OpenEnu\Kernel\Search\PostgresTsvectorIndexer;
use OpenEnu\Kernel\Search\SearchCatalogue;
use OpenEnu\Kernel\Search\SearchIndexListener;
use OpenEnu\Kernel\Search\SearchIndexerInterface;
use OpenEnu\Kernel\Setup\SetupRunner;
use OpenEnu\Kernel\Setup\TenantSetupInterface;
use OpenEnu\Kernel\Storage\FlysystemStorage;
use OpenEnu\Kernel\Storage\LocalUrlSigner;
use OpenEnu\Kernel\Storage\StorageInterface;
use OpenEnu\Kernel\Storage\UrlSignerInterface;

/**
 * Kernel services.
 *
 * Registered explicitly rather than by directory scan: this package is a
 * published contract, so what it exposes should be a deliberate list that
 * changes visibly - not whatever happens to be in a folder.
 *
 * Several services here are *defaults that later modules decorate*: the actor
 * provider, the audit logger, flags and progress. They are real implementations,
 * not no-ops, so code written today works and keeps working when Phase 3 and 4
 * replace them without touching a single call site.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    // ── module system ───────────────────────────────────────────────────────
    // Public: read-only, and the natural thing for tests and tooling to ask
    // "which modules exist and what do they declare?".
    $services->set(ModuleRegistry::class)->public();  // arguments set by the extension

    $services->set(ModuleRouteLoader::class)
        ->args([param('open_enu.modules'), param('kernel.environment')])
        ->tag('routing.loader');

    $services->set(ModuleListCommand::class);
    $services->set(RouteCoverageCommand::class);
    // The inventory is the answer to "does something like this already exist?"
    // (.ai/platform/PLAN.md §12.4). Built from the filesystem and the router, so it is
    // produced by the same command whether or not anything else works.
    $services->set(InventoryBuilder::class);
    $services->set(DuplicateNameHint::class);
    $services->set(InventoryCommand::class);

    // The API as MCP tools, generated from the committed spec and the router.
    // An endpoint added today is callable by an agent today; a curated list
    // would be a second place to remember.
    $services->set(ToolCatalogue::class)
        ->arg('$specPath', param('kernel.project_dir') . '/openapi.json');

    $services->set(McpServeCommand::class)->arg('$apiUrl', param('open_enu.api_url'));
    $services->set(ModuleDiffCommand::class);

    // ── http ────────────────────────────────────────────────────────────────
    $services->set(ApiExceptionListener::class)
        ->args([param('kernel.debug')])
        ->tag('kernel.event_listener', ['event' => 'kernel.exception']);

    $services->set(RequestIdListener::class);
    $services->set(FlagListener::class);

    // The claim that makes two firewalls actually isolated (ADR-0007).
    $services->set(AudienceListener::class)
        ->args([
            service('security.firewall.map'),
            service('request_stack'),
            param('open_enu.firewall_audiences'),
            service('logger'),
        ]);

    // Records that a session is impersonated, and refuses what it must not do.
    $services->set(ImpersonationListener::class);

    $services->set(HealthController::class)
        ->args([param('open_enu.app_stage'), \OpenEnu\Kernel\Kernel::VERSION])
        ->tag('controller.service_arguments');

    $services->set(StorageController::class)->tag('controller.service_arguments');

    // The browser bridge: #[ClientBroadcast] events reach the open tab.
    $services->set(ClientBroadcaster::class);

    $services->set(RealtimeTokenController::class)
        ->arg('$mercureSecret', env('MERCURE_JWT_SECRET'))
        ->arg('$secureCookies', param('open_enu.secure_cookies'))
        ->tag('controller.service_arguments');

    // ── tenancy ─────────────────────────────────────────────────────────────
    // Request-scoped; reset between worker messages so a leftover tenant can
    // never scope the next message.
    $services->set(ScopeContext::class)
        ->public()
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->set(OptimisticLock::class);

    // ── command bus ─────────────────────────────────────────────────────────
    $services->set(SnapshotCollector::class)->tag('kernel.reset', ['method' => 'reset']);
    $services->set(NullActorProvider::class);
    $services->alias(ActorProviderInterface::class, NullActorProvider::class);
    $services->set(LoggingAuditLogger::class);
    $services->alias(AuditLoggerInterface::class, LoggingAuditLogger::class);

    $services->set(MessengerCommandBus::class)
        ->args([service('messenger.default_bus')]);
    $services->alias(CommandBusInterface::class, MessengerCommandBus::class);

    // ── messaging ───────────────────────────────────────────────────────────
    $services->set(RequestIdMiddleware::class);
    $services->set(TenantScopeMiddleware::class);

    // ── logging ─────────────────────────────────────────────────────────────
    $services->set(RequestIdContextProvider::class);
    $services->set(ContextProcessor::class)
        ->args([tagged_iterator('open_enu.log_context')])
        ->tag('monolog.processor');

    // ── i18n ────────────────────────────────────────────────────────────────
    $services->set(AcceptLanguageProvider::class)->args([param('open_enu.enabled_locales')]);
    $services->set(LocaleResolver::class)->args([
        tagged_iterator('open_enu.locale_provider'),
        param('open_enu.enabled_locales'),
        param('kernel.default_locale'),
    ]);

    // ── encryption ──────────────────────────────────────────────────────────
    $services->set(DerivedKeyProvider::class)->args([env('APP_ENCRYPTION_KEY')]);
    $services->alias(KeyProviderInterface::class, DerivedKeyProvider::class);
    // Public: fetched by OpenEnuKernelBundle::boot() to configure the Doctrine
    // type, which the container cannot inject into.
    $services->set(Encryptor::class)->public();

    // ── storage ─────────────────────────────────────────────────────────────
    // Local adapter in dev; the S3 adapter arrives with Phase 8 and swaps in
    // here alone - no module changes, because no module knows which is running.
    $services->set('open_enu.storage.adapter', LocalFilesystemAdapter::class)
        ->args([param('open_enu.storage.root')]);
    $services->set('open_enu.storage.filesystem', Flysystem::class)
        ->args([service('open_enu.storage.adapter')]);
    $services->set(LocalUrlSigner::class);
    $services->alias(UrlSignerInterface::class, LocalUrlSigner::class);
    $services->set(FlysystemStorage::class)->args([service('open_enu.storage.filesystem')]);
    $services->alias(StorageInterface::class, FlysystemStorage::class);

    // ── cache ───────────────────────────────────────────────────────────────
    $services->set(\OpenEnu\Kernel\Cache\TenantCache::class)
        ->args([service('cache.app.taggable')]);

    // ── flags ───────────────────────────────────────────────────────────────
    // Decorated by the Settings module (Phase 4) to add per-tenant overrides.
    $services->set(StaticFlags::class)->args([param('open_enu.flags')]);
    $services->alias(FlagsInterface::class, StaticFlags::class);

    // ── progress ────────────────────────────────────────────────────────────
    $services->set(LoggingProgressReporter::class);
    $services->alias(ProgressReporterInterface::class, LoggingProgressReporter::class);

    // ── notifications ───────────────────────────────────────────────────────
    // Usable before the Notification module exists; that module decorates it.
    $services->set(LoggingNotifier::class);
    $services->alias(NotifierInterface::class, LoggingNotifier::class);

    // ── search ──────────────────────────────────────────────────────────────
    $services->set(PostgresTsvectorIndexer::class)
        ->args([service('doctrine.dbal.default_connection'), service(ScopeContext::class)]);
    $services->alias(SearchIndexerInterface::class, PostgresTsvectorIndexer::class);

    // Arguments are replaced by the extension, which merges every module's
    // search.php at compile time.
    $services->set(SearchCatalogue::class)->args([[]]);

    // Keeps the index in step on write, so no handler has to remember to.
    $services->set(SearchIndexListener::class);

    $services->set(SearchController::class)->tag('controller.service_arguments');

    // Records which routes the suite actually hits. Inert unless ROUTE_TRACE
    // names a file, so it costs nothing outside `make route-coverage`.
    $services->set(RouteTraceListener::class);

    // ── tenant lifecycle ────────────────────────────────────────────────────
    // Tagged iterators, so a module joins by implementing the interface. The
    // tags themselves are applied by registerForAutoconfiguration() in the
    // extension - `_instanceof` here would only cover services defined in this
    // file, which excludes every module service.
    $services->set(SetupRunner::class)->args([tagged_iterator('open_enu.tenant_setup')]);

    $services->set(GdprWalker::class)->args([tagged_iterator('open_enu.gdpr_subject')]);
    $services->set(GdprCommand::class);
    $services->set(TenantSeedCommand::class);
};
