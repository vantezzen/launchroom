<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What became of one line of an imported Compose file or template
 * (docs/17 §2.4): translated (✓), ignored (–), refused (✕) or imported
 * with something to check (⚠).
 */
enum BlueprintNoteKind: string
{
    case Translated = 'translated';
    case Ignored = 'ignored';
    case Refused = 'refused';
    case Warning = 'warning';
}
