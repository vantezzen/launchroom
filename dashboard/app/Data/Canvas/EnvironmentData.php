<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\EnvironmentKind;
use App\Models\Environment;
use Spatie\LaravelData\Data;

final class EnvironmentData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public EnvironmentKind $kind,
        /** Branch (glob) whose pushes deploy here. */
        public ?string $branch,
        /** Tag glob whose tags deploy here (docs/06 §2). */
        public ?string $tagPattern = null,
        /** Production after a rollback: pushes don't go live (docs/04 §8). */
        public bool $autoDeployPaused = false,
    ) {}

    public static function fromEnvironment(Environment $environment): self
    {
        return new self(
            $environment->public_id,
            $environment->name,
            $environment->slug,
            $environment->kind,
            $environment->triggers->branch,
            $environment->triggers->tagPattern,
            $environment->auto_deploy_paused,
        );
    }
}
