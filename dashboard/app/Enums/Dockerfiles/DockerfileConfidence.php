<?php

declare(strict_types=1);

namespace App\Enums\Dockerfiles;

/**
 * How sure the model says it is about a draft (shown, never trusted:
 * only the verification build decides, docs/20 §5).
 */
enum DockerfileConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
