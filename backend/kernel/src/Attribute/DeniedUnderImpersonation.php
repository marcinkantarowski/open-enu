<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Attribute;

/**
 * Refuses an action while the session is impersonated.
 *
 * Support needs to see what a customer sees. It does not need to delete their
 * account, change their billing, or remove their colleagues - and the blast
 * radius of a compromised operator session is decided entirely by which actions
 * carry this attribute.
 *
 * Put it on anything destructive, anything financial, and anything that changes
 * who can log in. The test is not "would support ever need this?" but "would I
 * want to explain to the customer that we did it while pretending to be them?".
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class DeniedUnderImpersonation
{
    public function __construct(
        /** Shown to the operator, so the refusal is comprehensible rather than a bare 403. */
        public string $because = 'This action is not available while viewing as another user.',
    ) {
    }
}
