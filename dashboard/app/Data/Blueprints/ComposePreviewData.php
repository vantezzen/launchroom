<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use Spatie\LaravelData\Data;

/**
 * What "Import a Compose file" shows before anything is created
 * (docs/17 §2.1). Secret-free: variables appear as keys only.
 */
final class ComposePreviewData extends Data
{
    /**
     * @param  list<BlueprintNote>  $notes
     * @param  list<ComposeInputData>  $inputs
     * @param  list<string>  $missingFiles
     * @param  list<SkippedServiceData>  $skipped
     */
    public function __construct(
        public ?BlueprintPreviewData $preview,
        public array $notes = [],
        public array $inputs = [],
        public array $missingFiles = [],
        public array $skipped = [],
        public ?string $suggestedName = null,
        /** The file read from the repository (`compose.yaml`). */
        public ?string $path = null,
        public ?string $error = null,
        public ?int $errorLine = null,
    ) {}
}
