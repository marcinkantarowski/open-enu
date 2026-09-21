<?php

declare(strict_types=1);

namespace OpenEnu\Kernel;

/**
 * Identity of the framework layer.
 *
 * This namespace is deliberately independent of the project's name: `make init`
 * renames the application and never touches `OpenEnu\Kernel`. That separation is
 * what lets a project built from this boilerplate later replace the path
 * repository with a versioned `open-enu/kernel` release and `composer update`
 * it, instead of hand-merging framework fixes forever.
 *
 * @see .ai/platform/adr/0016-kernel-packaging.md
 */
final class Kernel
{
    public const string NAME = 'open-enu/kernel';

    /** Bumped when a contract in this package changes. See BACKWARD_COMPATIBILITY.md. */
    public const string VERSION = '0.1.0';

    private function __construct()
    {
    }
}
