<?php

declare(strict_types=1);

namespace App\Support\Templates;

use App\Data\Templates\TemplateInputData;
use App\Support\Compose\YamlLineIndex;
use App\Support\Images\CuratedPin;
use App\Support\Images\ImageReference;

/**
 * A parsed template file (docs/17 §3.1): metadata, inputs and the Compose
 * `services` it is made of.
 */
final readonly class TemplateDefinition
{
    /**
     * @param  list<TemplateInputData>  $inputs
     * @param  array<array-key, mixed>  $services
     * @param  list<string>  $notes  requirements and limits shown on the detail sheet
     * @param  array<array-key, mixed>  $configs  Compose top-level `configs` (inline content only)
     * @param  list<string>  $repositories  canonical upstream repository URLs (D70)
     * @param  string|null  $starter  canonical URL of a public starter repository (stacks)
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
        public array $notes = [],
        public array $configs = [],
        public array $repositories = [],
        public ?string $starter = null,
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

    /**
     * The images this template pins, with their release notes link
     * (`x-launchroom.releases`): the versions launchroom tested it with
     * (docs/04 §14.1).
     *
     * @return list<CuratedPin>
     */
    public function pins(): array
    {
        $pins = [];

        foreach ($this->services as $service) {
            if (! is_array($service) || ! is_string($service['image'] ?? null)) {
                continue;
            }

            $reference = ImageReference::tryParse($service['image']);

            if (! $reference instanceof ImageReference || $reference->tag === null || $reference->digest === null) {
                continue;
            }

            $releases = is_array($service['x-launchroom'] ?? null) ? ($service['x-launchroom']['releases'] ?? null) : null;
            $pins[] = new CuratedPin($reference->registry, $reference->repository, $reference->tag, $reference->digest, $this->id, $this->name, is_string($releases) && self::isReleasesUrl($releases) ? $releases : null);
        }

        return $pins;
    }

    public static function isReleasesUrl(string $url): bool
    {
        return strlen($url) <= 255 && preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}(/[A-Za-z0-9._~/-]*)?$#D', $url) === 1;
    }

    public function needsRepository(): bool
    {
        return array_any($this->inputs, static fn (TemplateInputData $input): bool => $input->type === 'repository');
    }
}
