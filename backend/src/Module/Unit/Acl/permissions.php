<?php

declare(strict_types=1);

/**
 * `unit.view` reads the tree - every member does, to pick a unit on a form.
 * `unit.manage` changes its shape, which changes who sees what (ADR-0025).
 */
return [
    'unit.view',
    'unit.manage',
];
