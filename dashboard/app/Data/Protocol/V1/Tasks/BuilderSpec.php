<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Data\Protocol\MapTransformer;
use App\Data\Protocol\V1\PhpExtension;
use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Data;

/**
 * The builder decision (protocol BuilderSpec, docs/04 §2–3).
 */
final class BuilderSpec extends Data
{
    /**
     * @param  'auto'|'dockerfile'|'railpack'  $kind
     * @param  list<string>|null  $disabledDecisions
     * @param  array<string, string>|null  $railpackEnv  non-secret Railpack settings (RAILPACK_SPA_OUTPUT_DIR, …)
     * @param  list<PhpExtension>|null  $phpExtensions
     */
    public function __construct(
        public string $kind,
        public ?string $dockerfilePath = null,
        public ?string $dockerfile = null,
        public ?string $preset = null,
        public ?string $railpackVersion = null,
        public ?array $disabledDecisions = null,
        public ?string $installCommand = null,
        public ?string $buildCommand = null,
        public ?string $startCommand = null,
        #[WithTransformer(MapTransformer::class)]
        public ?array $railpackEnv = null,
        public ?array $phpExtensions = null,
    ) {}
}
