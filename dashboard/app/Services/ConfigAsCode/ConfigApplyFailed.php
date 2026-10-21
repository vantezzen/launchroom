<?php

declare(strict_types=1);

namespace App\Services\ConfigAsCode;

use App\Data\ConfigAsCode\ConfigFileProblemData;
use RuntimeException;

/**
 * A valid launchroom.json that can't be applied to this environment (a
 * required secret is missing, a domain is taken, no server matches the
 * placement): nothing is written (docs/18 §3.3).
 */
final class ConfigApplyFailed extends RuntimeException
{
    /**
     * @param  list<ConfigFileProblemData>  $problems
     */
    public function __construct(
        public readonly array $problems,
    ) {
        parent::__construct($problems[0]->message ?? 'launchroom.json could not be applied.');
    }
}
