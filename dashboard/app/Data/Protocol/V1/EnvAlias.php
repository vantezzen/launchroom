<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Enums\EnvAliasSource;
use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * BuildSummary `envAliases[]` (protocol EnvAlias, docs/06 §3.1): a
 * framework's own variable set to a system value per environment. A
 * variable the user set always wins.
 */
final class EnvAlias extends Data
{
    public function __construct(
        #[Regex(Grammar::ENV_NAME)]
        public string $name,
        public EnvAliasSource $from,
    ) {}
}
