<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Models\Project;
use Spatie\LaravelData\Data;

final class ProjectData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        /** Slot 0–7 of the categorical data palette (direction.md §8.4). */
        public int $colorSlot = 0,
        /** The project icon (docs/08 §13); null → monogram. */
        public ?string $iconUrl = null,
    ) {}

    public static function fromProject(Project $project): self
    {
        return new self($project->public_id, $project->name, $project->slug, $project->color_slot, $project->iconUrl());
    }
}
