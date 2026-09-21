<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Support;

/** Records the order in which collaborators were invoked. */
final class CallOrder
{
    /** @var list<string> */
    private array $calls = [];

    public function record(string $name): void
    {
        $this->calls[] = $name;
    }

    /** @return list<string> */
    public function calls(): array
    {
        return $this->calls;
    }
}
