<?php

declare(strict_types=1);

namespace App\Support\Compose;

use App\Data\Blueprints\BlueprintNote;
use App\Data\Blueprints\ComposeInputData;
use App\Data\Blueprints\ProjectBlueprint;
use App\Data\Blueprints\ServiceChoiceData;
use App\Data\Blueprints\SkippedServiceData;
use App\Enums\BlueprintNoteKind;

/**
 * The result of translating a Compose file: a valid blueprint plus what
 * the preview shows around it.
 */
final readonly class ComposeTranslation
{
    /**
     * @param  list<BlueprintNote>  $notes  about the file as a whole
     * @param  list<ComposeInputData>  $inputs
     * @param  list<string>  $missingFiles  `env_file` paths nobody pasted
     * @param  list<SkippedServiceData>  $skipped
     * @param  array<string, ServiceChoiceData>  $choices  by blueprint key
     */
    public function __construct(
        public ProjectBlueprint $blueprint,
        public array $notes,
        public array $inputs,
        public array $missingFiles,
        public array $skipped,
        public array $choices,
    ) {}

    /**
     * Notes that make a template invalid (docs/17 §3.3): anything refused
     * or ignored, anywhere.
     *
     * @return list<string>
     */
    public function strictProblems(): array
    {
        $problems = [];

        foreach ($this->notes as $note) {
            if (in_array($note->kind, [BlueprintNoteKind::Refused, BlueprintNoteKind::Ignored, BlueprintNoteKind::Warning], true)) {
                $problems[] = ($note->line !== null ? "line {$note->line}: " : '').$note->message;
            }
        }

        foreach ($this->blueprint->services as $service) {
            foreach ($service->notes as $note) {
                if (in_array($note->kind, [BlueprintNoteKind::Refused, BlueprintNoteKind::Ignored, BlueprintNoteKind::Warning], true)) {
                    $problems[] = $service->key.($note->line !== null ? " (line {$note->line})" : '').': '.$note->message;
                }
            }
        }

        foreach ($this->skipped as $skipped) {
            $problems[] = "{$skipped->name}: {$skipped->reason}";
        }

        return $problems;
    }
}
