<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Report `ingress.waf` (docs/03 §13.4): the web firewall's state at an
 * edge. `on`: at least one route runs it; `off`: none asks for it;
 * `error`: Caddy refused the config with the firewall and serves the routes
 * without it; `unsupported`: the pinned Caddy has no firewall module.
 */
final class WafStatus extends Data
{
    public const string LABEL = '/^[A-Za-z0-9][A-Za-z0-9 ._\/+-]{0,63}$/D';

    public function __construct(
        #[In(['on', 'off', 'error', 'unsupported'])]
        public string $state,
        #[Min(0)]
        public int $routes,
        #[Regex(self::LABEL)]
        public string|Optional $engine = new Optional,
        #[Regex(self::LABEL)]
        public string|Optional $ruleSet = new Optional,
        #[Min(0)]
        public int|Optional $droppedRecords = new Optional,
        #[Max(1024)]
        public string|Optional $message = new Optional,
    ) {}
}
