<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use App\Enums\ServiceKind;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * One node of the canvas a blueprint creates (docs/17 §4).
 */
final class BlueprintServicePreviewData extends Data
{
    /**
     * @param  list<BlueprintVariablePreviewData>  $variables
     * @param  list<BlueprintNote>  $notes
     */
    public function __construct(
        public string $key,
        public string $name,
        public ServiceKind $kind,
        public string $logo,
        /** "Managed PostgreSQL 17", "umami:3.4.0", "Worker of web", "Built from ./api". */
        public string $subtitle,
        #[LiteralTypeScriptType("'public' | 'app' | 'data'")]
        public string $group,
        public ?int $port,
        public bool $public,
        public int $replicas,
        public float $cpus,
        public int $memoryMb,
        public int $volumes,
        public array $variables,
        public array $notes,
        public ?ServiceChoiceData $choice = null,
    ) {}
}
