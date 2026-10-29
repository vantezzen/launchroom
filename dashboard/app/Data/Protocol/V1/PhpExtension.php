<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Support\Builds\PhpExtensions;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Data;

/**
 * A PHP extension a build adds because a connected database needs its
 * driver, and that database's engine (protocol PhpExtension, docs/04 §3.1).
 * Also stored in ServiceSpec::$phpExtensions.
 */
final class PhpExtension extends Data
{
    public function __construct(
        #[In(PhpExtensions::NAMES)]
        public string $name,
        #[In(PhpExtensions::ENGINES)]
        public string $for,
    ) {}
}
