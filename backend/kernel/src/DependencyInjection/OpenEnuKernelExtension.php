<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\DependencyInjection;

use OpenEnu\Kernel\Doctrine\ScopeFilter;
use OpenEnu\Kernel\Doctrine\Type\EncryptedStringType;
use OpenEnu\Kernel\Doctrine\Type\EncryptedTenantStringType;
use OpenEnu\Kernel\Gdpr\GdprSubjectInterface;
use OpenEnu\Kernel\I18n\LocalePreferenceProviderInterface;
use OpenEnu\Kernel\Logging\LogContextProviderInterface;
use OpenEnu\Kernel\Security\TokenAudience;
use OpenEnu\Kernel\Flags\FlagProviderInterface;
use OpenEnu\Kernel\Search\SearchCatalogue;
use OpenEnu\Kernel\Setup\TenantSetupInterface;
use OpenEnu\Kernel\Module\ModuleLocator;
use OpenEnu\Kernel\Module\ModuleManifest;
use OpenEnu\Kernel\Module\ModuleDeclarations;
use OpenEnu\Kernel\Notification\NotificationProviderInterface;
use OpenEnu\Kernel\Module\ModuleRegistry;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Argument\BoundArgument;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Turns a directory under src/Module/ into a live module.
 *
 * Everything a module needs in order to work - services, ORM mapping, migration
 * path, permissions - is derived here from its `module.yaml` and its directory
 * layout. Adding a module never means editing a central file, which is the
 * property that lets an agent add one without reading the rest of the system.
 *
 * Routes are the one exception: they are loaded by ModuleRouteLoader, because
 * routing runs outside the container-compile pass.
 *
 * @see \OpenEnu\Kernel\Routing\ModuleRouteLoader
 */
final class OpenEnuKernelExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Directories whose classes become container services.
     *
     * Deliberately a list and not "everything": Entity, Dto, Contract, Event and
     * Message hold value objects and data. Registering those as services would
     * make them injectable, which invites exactly the coupling the module
     * boundaries exist to prevent.
     */
    private const array SERVICE_DIRS = [
        'Service',
        'Repository',
        'Listener',
        'Handler',
        'Setup',
        // Console/ holds CLI entry points, which ARE services. Command/ holds
        // CQRS message objects, which are not - registering those would make
        // every intent injectable and invite it to be called directly.
        'Console',
        // The whole of Security/, not just Voter/: user providers,
        // authenticators and security listeners live here too, and a module
        // whose provider silently is not a service fails at container compile
        // with an error that names the wrong thing.
        'Security',
    ];

    private const string CONTROLLER_DIR = 'Controller';

    /**
     * Interfaces a module implements to join a kernel extension point.
     *
     * @var array<class-string, string>
     */
    private const array AUTOCONFIGURED_TAGS = [
        TenantSetupInterface::class => 'open_enu.tenant_setup',
        FlagProviderInterface::class => 'open_enu.flag_provider',
        NotificationProviderInterface::class => 'open_enu.notification_provider',
        GdprSubjectInterface::class => 'open_enu.gdpr_subject',
        LogContextProviderInterface::class => 'open_enu.log_context',
        LocalePreferenceProviderInterface::class => 'open_enu.locale_provider',
    ];

    /** @var array<string, ModuleManifest>|null */
    private ?array $modules = null;

    public function getAlias(): string
    {
        return 'open_enu_kernel';
    }

    /**
     * Inject configuration other bundles own, before they load it.
     *
     * ORM mappings and migration paths belong to DoctrineBundle and
     * DoctrineMigrationsBundle respectively; prepending is how a module's
     * Entity/ and Migrations/ directories reach them without the application
     * listing every module by hand.
     */
    public function prepend(ContainerBuilder $container): void
    {
        $modules = $this->modules($container);

        $mappings = [];
        $migrationPaths = [];

        foreach ($modules as $module) {
            if ($module->hasDir('Entity')) {
                $mappings[$module->name] = [
                    'type' => 'attribute',
                    'is_bundle' => false,
                    'dir' => $module->dir('Entity'),
                    'prefix' => $module->namespace() . 'Entity',
                    'alias' => $module->name,
                ];
            }

            // Each module owns its migration history. Versions are timestamps,
            // so ordering across modules stays globally correct even though the
            // files live apart.
            if ($module->hasDir('Migrations')) {
                $migrationPaths[$module->namespace() . 'Migrations'] = $module->dir('Migrations');
            }
        }

        // The scope filter is registered and enabled by the kernel, not by the
        // application. A protection the application has to remember to switch
        // on is one that a new project will ship without.
        $orm = [
            'filters' => [
                ScopeFilter::NAME => [
                    'class' => ScopeFilter::class,
                    'enabled' => true,
                ],
            ],
        ];
        if ($mappings !== []) {
            $orm['mappings'] = $mappings;
        }
        $container->prependExtensionConfig('doctrine', [
            'dbal' => ['types' => [
                EncryptedStringType::NAME => EncryptedStringType::class,
                EncryptedTenantStringType::NAME => EncryptedTenantStringType::class,
            ]],
            'orm' => $orm,
        ]);
        // The kernel owns infrastructure schema (the search index). Modules own
        // their own; nobody owns everyone's.
        $migrationPaths['OpenEnu\\Kernel\\Migrations'] = \dirname(__DIR__, 2) . '/migrations';

        $container->prependExtensionConfig('doctrine_migrations', ['migrations_paths' => $migrationPaths]);

        // Each module ships its own catalogues, so translations live beside the
        // code that uses them rather than in one file every module edits.
        $translationPaths = [];
        foreach ($modules as $module) {
            if ($module->hasDir('i18n')) {
                $translationPaths[] = $module->dir('i18n');
            }
        }
        if ($translationPaths !== []) {
            $container->prependExtensionConfig('framework', [
                'translator' => ['paths' => $translationPaths],
            ]);
        }

        // A module's templates are its own, like its migrations and catalogues.
        // Each gets a lowercase namespace: @identity/email/verify.html.twig.
        $twigPaths = [];
        foreach ($modules as $module) {
            if ($module->hasDir('templates')) {
                $twigPaths[$module->dir('templates')] = $module->slug();
            }
        }
        if ($twigPaths !== []) {
            $container->prependExtensionConfig('twig', ['paths' => $twigPaths]);
        }
    }

    /** @param array<mixed> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        // Deployment stage, distinct from APP_ENV: staging runs APP_ENV=prod so
        // Symfony's when@prod config keeps applying (ADR-0011).
        $container->setParameter('open_enu.app_stage', '%env(default:open_enu_stage_default:APP_STAGE)%');
        $container->setParameter('open_enu_stage_default', 'local');

        // Both locales ship from the start; a third is a file, not a project
        // (ADR-0020). Checked by `make i18n-check`.
        $container->setParameter('open_enu.enabled_locales', ['en', 'pl']);

        // Where the local storage adapter keeps files. Outside public/, so a
        // file is only ever reachable through a signed, expiring URL.
        $container->setParameter('open_enu.storage.root', '%kernel.project_dir%/var/storage');
        $container->setParameter('open_enu.storage.max_upload_mb', '%env(int:default:open_enu_upload_default:STORAGE_MAX_UPLOAD_MB)%');
        $container->setParameter('open_enu_upload_default', 25);

        // Static flag defaults. The Settings module (Phase 4) decorates the
        // flag service to add per-tenant overrides on top of these.
        $container->setParameter('open_enu.flags', []);

        // Scalars modules bind by name; see self::bindings().
        //
        // `default::X` would default to the EMPTY-NAMED parameter, which is
        // null - so an unset variable becomes null and every `string` type-hint
        // explodes at construction. Default to a real empty string instead.
        $container->setParameter('open_enu.empty', '');
        $container->setParameter('open_enu.app_url', '%env(default:open_enu.empty:APP_URL)%');

        // The API's own canonical URL. DEFAULT_URI rather than a new variable:
        // Symfony already needs it to generate absolute URLs from the CLI, it is
        // already derived from DOMAIN by `make env`, and a second variable
        // meaning the same thing is a second variable to disagree.
        $container->setParameter('open_enu.api_url', '%env(default:open_enu.empty:DEFAULT_URI)%');
        $container->setParameter('open_enu.manager_url', '%env(default:open_enu.empty:MANAGER_URL)%');
        $container->setParameter('open_enu.site_url', '%env(default:open_enu.empty:SITE_URL)%');
        $container->setParameter('open_enu.mailer_from', '%env(default:open_enu.empty:MAILER_FROM)%');
        // Empty means host-only. Every cookie this system sets belongs to
        // api.${DOMAIN} and nowhere else - the Mercure subscriber cookie too,
        // since the hub is served from that host. A Domain attribute would also
        // reach every host beneath it, a staging stack at stg.${DOMAIN} included.
        $container->setParameter('open_enu.cookie_domain', '');

        // Secure cookies everywhere except a plain-HTTP local run; the dev stack
        // is HTTPS, so this stays true and nothing has to remember to flip it.
        $container->setParameter('open_enu.secure_cookies', true);

        // Which realm each firewall accepts (ADR-0007). A firewall missing from
        // this map authenticates nothing - an unclassified surface is refused
        // rather than trusted.
        $container->setParameter('open_enu.firewall_audiences', [
            'api' => TokenAudience::App->value,
            'manager_api' => TokenAudience::Manager->value,
        ]);

        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config'));
        $loader->load('services.php');

        // Autoconfiguration is GLOBAL; `_instanceof` in services.php is not - it
        // applies only to services defined in that file. Module services are
        // registered further down by registerClasses(), so without this they
        // would silently never be tagged, and every tagged_iterator the kernel
        // builds would come back empty.
        foreach (self::AUTOCONFIGURED_TAGS as $interface => $tag) {
            $container->registerForAutoconfiguration($interface)->addTag($tag);
        }

        $modules = $this->modules($container);

        $prototype = (new Definition())
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setBindings(self::bindings());

        foreach ($modules as $module) {
            foreach (self::SERVICE_DIRS as $sub) {
                if ($module->hasDir($sub)) {
                    $loader->registerClasses(
                        clone $prototype,
                        $module->namespace() . str_replace('/', '\\', $sub) . '\\',
                        $module->dir($sub) . '/{*,**/*}.php',
                    );
                }
            }

            if ($module->hasDir(self::CONTROLLER_DIR)) {
                // controller.service_arguments is what lets a controller receive
                // its dependencies as action arguments. Without the tag, plain
                // (non-AbstractController) classes fail at runtime with an
                // unhelpful "cannot resolve argument".
                $controllerPrototype = (clone $prototype)->addTag('controller.service_arguments');
                $loader->registerClasses(
                    $controllerPrototype,
                    $module->namespace() . 'Controller\\',
                    $module->dir(self::CONTROLLER_DIR) . '/{*,**/*}.php',
                );
            }
        }

        $this->aliasContracts($modules, $container);

        $container->getDefinition(ModuleRegistry::class)
            ->setArguments([$this->describe($modules), ModuleDeclarations::permissions($modules)]);

        $container->getDefinition(SearchCatalogue::class)
            ->setArguments([ModuleDeclarations::searchable($modules)]);

        // Consumed by the route loader, which runs outside this pass.
        $container->setParameter('open_enu.modules', $this->describe($modules));
    }

    /**
     * Alias each module contract to its single implementation.
     *
     * A module publishes `Contract\FooInterface` and implements it once, in its
     * own `Service/`. Without this, every module has to hand-write that alias,
     * and the failure when they forget is a container error naming a class the
     * author never typed - which is a poor introduction to the convention.
     *
     * Only aliases when there is EXACTLY one implementation. Two means the
     * module has a choice to make, and guessing would be worse than the error.
     *
     * @param array<string, ModuleManifest> $modules
     */
    private function aliasContracts(array $modules, ContainerBuilder $container): void
    {
        foreach ($modules as $module) {
            if (!$module->hasDir('Contract')) {
                continue;
            }

            foreach ((glob($module->dir('Contract') . '/*.php') ?: []) as $file) {
                $interface = $module->namespace() . 'Contract\\' . basename($file, '.php');

                if (!interface_exists($interface) || $container->hasAlias($interface) || $container->has($interface)) {
                    continue;
                }

                $implementations = [];
                foreach ($container->getDefinitions() as $id => $definition) {
                    $class = $definition->getClass() ?? $id;

                    if (!str_starts_with($class, $module->namespace()) || !class_exists($class)) {
                        continue;
                    }
                    if (is_subclass_of($class, $interface)) {
                        $implementations[] = $id;
                    }
                }

                if (\count($implementations) === 1) {
                    $container->setAlias($interface, $implementations[0])->setPublic(false);
                }
            }
        }
    }

    /**
     * Scalars any module service may accept by argument name.
     *
     * Without this, every module that needs the app's URL or the sender address
     * reads an env var itself - and they drift, because nothing makes them
     * agree. These come from the same derived configuration as everything else
     * (.ai/platform/PLAN.md §11), so a module just names what it wants:
     *
     *     public function __construct(private string \$appUrl) {}
     *
     * @return array<string, BoundArgument>
     */
    private static function bindings(): array
    {
        $bind = static fn (string $expression): BoundArgument => new BoundArgument($expression, false);

        return [
            'string $appUrl' => $bind('%open_enu.app_url%'),
            'string $apiUrl' => $bind('%open_enu.api_url%'),
            'string $managerUrl' => $bind('%open_enu.manager_url%'),
            'string $siteUrl' => $bind('%open_enu.site_url%'),
            'string $fromAddress' => $bind('%open_enu.mailer_from%'),
            'string $cookieDomain' => $bind('%open_enu.cookie_domain%'),
            'bool $secureCookies' => $bind('%open_enu.secure_cookies%'),
            'string $appStage' => $bind('%open_enu.app_stage%'),
            'array $enabledLocales' => $bind('%open_enu.enabled_locales%'),
            'int $maxUploadMb' => $bind('%open_enu.storage.max_upload_mb%'),
        ];
    }

    /** @return array<string, ModuleManifest> */
    private function modules(ContainerBuilder $container): array
    {
        if ($this->modules === null) {
            $dir = $container->getParameter('kernel.project_dir') . '/src/Module';
            \assert(\is_string($dir));
            $this->modules = (new ModuleLocator($dir))->locate();

            // Discovery reads the filesystem, so the container must rebuild when
            // a module is added, removed, or its manifest edited.
            $container->addResource(new \Symfony\Component\Config\Resource\DirectoryResource(
                \dirname($dir) . '/Module',
                '/module\.yaml$/',
            ));
        }

        return $this->modules;
    }

    /**
     * @param array<string, ModuleManifest> $modules
     *
     * @return array<string, array{description: string, depends: list<string>, path: string, slug: string}>
     */
    private function describe(array $modules): array
    {
        $out = [];
        foreach ($modules as $name => $m) {
            $out[$name] = [
                'description' => $m->description,
                'depends' => $m->depends,
                'path' => $m->path,
                'slug' => $m->slug(),
            ];
        }

        return $out;
    }

}
