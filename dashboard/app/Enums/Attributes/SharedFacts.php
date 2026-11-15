<?php

declare(strict_types=1);

namespace App\Enums\Attributes;

use Attribute;

/**
 * Extra per-case facts of an enum that the frontend reads beside `label()`
 * and `tone()`, shipped through resources/js/types/vocabulary.generated.ts
 * (docs/10 §3). Each name is a public, argument-free method returning a
 * bool, int or string.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SharedFacts
{
    /** @var list<string> */
    public array $methods;

    public function __construct(string ...$methods)
    {
        $this->methods = array_values($methods);
    }
}
