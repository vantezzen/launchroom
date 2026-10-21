<?php

declare(strict_types=1);

namespace App\Support\Templates;

use App\Data\Templates\TemplateInputData;
use App\Support\Compose\YamlLineIndex;

/**
 * A parsed template file (docs/17 §3.1): metadata, inputs and the Compose
 * `services` it is made of.
 */
final readonly class TemplateDefinition
{
    /**
     * @param  list<TemplateInputData>  $inputs
     * @param  array<array-key, mixed>  $services
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
        public array $services,
        public YamlLineIndex $lines,
    ) {}

    public function input(string $id): ?TemplateInputData
    {
        foreach ($this->inputs as $input) {
            if ($input->id === $id) {
                return $input;
            }
        }

        return null;
    }

    public function needsRepository(): bool
    {
        return array_any($this->inputs, static fn (TemplateInputData $input): bool => $input->type === 'repository');
    }
}
