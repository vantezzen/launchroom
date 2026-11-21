<?php

declare(strict_types=1);

namespace App\Enums\Commands;

/**
 * Where a listed command comes from (D105): the build's framework presets
 * or the team's launchroom.json (`deploy.commands`).
 */
enum CommandSource: string
{
    case Framework = 'framework';
    case Config = 'config';
}
