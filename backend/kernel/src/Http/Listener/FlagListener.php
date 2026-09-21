<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Http\Listener;

use OpenEnu\Kernel\Flags\Flag;
use OpenEnu\Kernel\Flags\FlagsInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Enforces `#[Flag]` on a controller action.
 *
 * A disabled flag produces 404, not 403. A 403 confirms the endpoint exists and
 * is merely withheld, which is information a kill switch should not be leaking -
 * "not built yet" and "turned off after an incident" should look identical from
 * outside.
 *
 * Runs on ControllerArguments rather than Controller so `#[Flag]` can be read
 * from the resolved attributes Symfony has already collected.
 */
final readonly class FlagListener
{
    public function __construct(private FlagsInterface $flags)
    {
    }

    #[AsEventListener(event: 'kernel.controller_arguments')]
    public function __invoke(ControllerArgumentsEvent $event): void
    {
        /** @var list<Flag> $attributes */
        $attributes = $event->getAttributes(Flag::class);

        foreach ($attributes as $flag) {
            if (!$this->flags->enabled($flag->identifier, $flag->default)) {
                throw new NotFoundHttpException();
            }
        }
    }
}
