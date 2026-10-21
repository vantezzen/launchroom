<?php

declare(strict_types=1);

namespace App\Data\Templates;

use App\Data\Blueprints\BlueprintPreviewData;
use Spatie\LaravelData\Data;

/**
 * A template as the gallery shows it: card, detail sheet and guided setup
 * (docs/17 §4).
 */
final class TemplateSummaryData extends Data
{
    /**
     * @param  list<TemplateInputData>  $inputs
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $tagline,
        public string $description,
        public string $category,
        public string $logo,
        public ?string $website,
        public ?string $license,
        public array $inputs,
        public BlueprintPreviewData $preview,
        /** Built from the user's repository (stacks). */
        public bool $needsRepository,
    ) {}
}
