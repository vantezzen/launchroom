<?php

declare(strict_types=1);

namespace App\Enums\Http;

/** The level of a `/p/…` or `/servers/…` path that didn't resolve (docs/08 §24). */
enum MissingResource: string
{
    case Project = 'project';
    case Environment = 'environment';
    case Service = 'service';
    case Server = 'server';
}
