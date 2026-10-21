<?php

declare(strict_types=1);

namespace App\Enums\Dockerfiles;

/**
 * Model providers for Dockerfile generation (docs/20 §1). Only Anthropic's
 * Claude API for now.
 */
enum AiProvider: string
{
    case Anthropic = 'anthropic';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Claude API',
        };
    }
}
