<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * BuildSummary `phpDependencyExtensions[]` (protocol PhpDependencyExtension):
 * a PHP extension a build adds because a composer dependency requires it
 * (`ext-<name>` in composer.lock), and the first package that does.
 */
final class PhpDependencyExtension extends Data
{
    public function __construct(
        #[Regex(Grammar::PHP_EXTENSION_IDENT)]
        public string $name,
        #[Max(128), Regex(Grammar::COMPOSER_PACKAGE)]
        public string $for,
    ) {}
}
