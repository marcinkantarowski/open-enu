<?php

declare(strict_types=1);

/**
 * The manager realm authorises by ROLE, not by these permissions.
 *
 * Tenant permissions are dotted strings resolved from a membership; operator
 * authority is a role on the operator record. Keeping the two vocabularies
 * disjoint is deliberate - it means no tenant role can ever accidentally satisfy
 * an operator check, whatever someone writes in a controller attribute.
 */
return [];
