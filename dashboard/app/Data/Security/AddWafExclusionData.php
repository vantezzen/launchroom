<?php

declare(strict_types=1);

namespace App\Data\Security;

use App\Support\Protocol\Grammar;
use App\Support\Protocol\Limits;
use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * A firewall exception (docs/22 §6.4, docs/03 §13.3): a CRS detection rule,
 * optionally only below a path prefix and for one part of the request.
 */
final class AddWafExclusionData extends Data
{
    public const string PATH = Grammar::WAF_PATH_PREFIX;

    public const string TARGET = '/^(?:(?:ARGS|REQUEST_COOKIES|REQUEST_HEADERS):[A-Za-z0-9_.-]{1,64}|ARGS_NAMES|REQUEST_COOKIES_NAMES|REQUEST_BODY|XML|FILES)$/D';

    public function __construct(
        #[Between(Limits::WAF_EXCLUDABLE_MIN, Limits::WAF_EXCLUDABLE_MAX)]
        public int $rule,
        #[Regex(self::PATH)]
        public ?string $path = null,
        #[Regex(self::TARGET)]
        public ?string $target = null,
        #[Max(200)]
        public ?string $note = null,
    ) {}
}
