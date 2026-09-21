<?php

declare(strict_types=1);

namespace OpenEnu\Kernel;

use OpenEnu\Kernel\Crypto\Encryptor;
use OpenEnu\Kernel\DependencyInjection\OpenEnuKernelExtension;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Doctrine\Type\EncryptedStringType;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The framework layer, as a Symfony bundle.
 *
 * Registered by the application in config/bundles.php. Nothing in this package
 * knows anything about `App\` - that direction of dependency is what keeps the
 * kernel replaceable by a versioned release later (ADR-0016).
 */
final class OpenEnuKernelBundle extends Bundle
{
    // Narrowed from the interface's ?ExtensionInterface: this bundle always has
    // one, and PHP allows a covariant return type. Better than annotating away
    // a correct warning.
    public function getContainerExtension(): ExtensionInterface
    {
        // Symfony types this property as ExtensionInterface|false|null, where
        // `false` means "already looked, there is none". We always have one.
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new OpenEnuKernelExtension();
        }

        return $this->extension;
    }

    /**
     * Hand the encrypted column type its collaborators.
     *
     * Doctrine instantiates DBAL types itself, without the container, so a type
     * that needs services has no way to receive them. Bundle::boot() is the one
     * hook that runs on every kernel boot - web and CLI alike - which matters:
     * a console command that reads an encrypted column must decrypt it too.
     */
    public function boot(): void
    {
        $container = $this->container;
        if ($container === null) {
            return;
        }

        $encryptor = $container->get(Encryptor::class);
        $scope = $container->get(ScopeContext::class);

        \assert($encryptor instanceof Encryptor);
        \assert($scope instanceof ScopeContext);

        // Both types share the static collaborators; the subclass only differs
        // in which key it asks for.
        EncryptedStringType::configure($encryptor, $scope);
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
