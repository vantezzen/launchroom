<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Data\Templates\TemplateSummaryData;
use App\Services\Blueprints\EngineVersions;
use App\Support\Blueprints\BlueprintPreview;
use App\Support\Templates\TemplateCompiler;
use App\Support\Templates\TemplateDefinition;
use App\Support\Templates\TemplateInvalid;
use Illuminate\Filesystem\Filesystem;

/**
 * The curated templates shipped in `resources/templates/*.yaml`
 * (docs/17 §3.2). Files are part of the release, reviewed like code and
 * validated by `make lint-templates`; a file that doesn't parse is left
 * out of the catalog (and fails the lint).
 */
final class TemplateCatalog
{
    /** @var array<string, TemplateDefinition>|null */
    private ?array $templates = null;

    public function __construct(
        private readonly Filesystem $files,
        private readonly EngineVersions $engines,
    ) {}

    public static function directory(): string
    {
        return resource_path('templates');
    }

    /**
     * @return array<string, TemplateDefinition> by id, ordered by category then name
     */
    public function all(): array
    {
        if ($this->templates !== null) {
            return $this->templates;
        }

        $templates = [];

        foreach ($this->paths() as $id => $path) {
            try {
                $templates[$id] = TemplateCompiler::parse($this->files->get($path), $id);
            } catch (TemplateInvalid) {
                continue;
            }
        }

        $order = array_flip(array_keys(TemplateCompiler::CATEGORIES));
        uasort($templates, static fn (TemplateDefinition $a, TemplateDefinition $b): int => [$order[$a->category] ?? 99, $a->name] <=> [$order[$b->category] ?? 99, $b->name]);

        return $this->templates = $templates;
    }

    public function template(string $id): ?TemplateDefinition
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * @return array<string, string> id => path
     */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->files->glob(self::directory().'/*.yaml') as $path) {
            if (is_string($path)) {
                $paths[basename($path, '.yaml')] = $path;
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * Problems per file (`make lint-templates`).
     *
     * @return array<string, list<string>>
     */
    public function lint(): array
    {
        $problems = [];

        foreach ($this->paths() as $id => $path) {
            try {
                $problems[$id] = TemplateCompiler::lint(TemplateCompiler::parse($this->files->get($path), $id), $this->engines->all());
            } catch (TemplateInvalid $e) {
                $problems[$id] = $e->problems;
            }
        }

        return $problems;
    }

    /**
     * @return list<TemplateSummaryData>
     */
    public function summaries(): array
    {
        $summaries = [];

        foreach ($this->all() as $template) {
            $summary = $this->summary($template);

            if ($summary instanceof TemplateSummaryData) {
                $summaries[] = $summary;
            }
        }

        return $summaries;
    }

    public function summary(TemplateDefinition $template): ?TemplateSummaryData
    {
        $samples = [];

        foreach ($template->inputs as $input) {
            // Stand-ins for the picture only; the real values come from the guided setup.
            $samples[$input->id] = match ($input->type) {
                'email' => 'admin@example.com',
                'text' => $input->placeholder ?? 'example',
                default => '',
            };
        }

        try {
            $translation = TemplateCompiler::compile($template, $samples, $this->engines->all(), $template->needsRepository());
        } catch (TemplateInvalid) {
            return null;
        }

        return new TemplateSummaryData(
            id: $template->id,
            name: $template->name,
            tagline: $template->tagline,
            description: $template->description,
            category: $template->category,
            logo: $template->logo,
            website: $template->website,
            license: $template->license,
            inputs: $template->inputs,
            preview: BlueprintPreview::of($translation->blueprint),
            needsRepository: $template->needsRepository(),
            notes: $template->notes,
        );
    }
}
