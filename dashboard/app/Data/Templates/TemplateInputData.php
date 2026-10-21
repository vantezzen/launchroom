<?php

declare(strict_types=1);

namespace App\Data\Templates;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * One of the few things a template asks for in its guided setup
 * (docs/17 §3.1). Everything else is generated.
 */
final class TemplateInputData extends Data
{
    public function __construct(
        public string $id,
        public string $label,
        #[LiteralTypeScriptType("'text' | 'email' | 'domain' | 'repository'")]
        public string $type,
        public bool $optional = false,
        public ?string $description = null,
        public ?string $placeholder = null,
    ) {}
}
