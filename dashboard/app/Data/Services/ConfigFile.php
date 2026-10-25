<?php

declare(strict_types=1);

namespace App\Data\Services;

use Spatie\LaravelData\Data;

/**
 * A config file of a service (D65, docs/17 §2.3): launchroomd writes it into
 * the container before it starts, read-only (0444, root), at `path`. The
 * content is literal text, part of the service's settings and visible to
 * anyone who can see them: secrets stay in variables, which the program
 * reads from its environment. Rules: App\Support\Services\ConfigFileRules.
 */
final class ConfigFile extends Data
{
    public function __construct(
        public string $path,
        public string $content,
    ) {}
}
