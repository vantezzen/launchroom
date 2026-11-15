<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

/**
 * What an engine is for. The import step recommends the first engine of a
 * category an app needs and none of whose engines the repository already
 * asks for.
 */
enum DatabaseEngineCategory: string
{
    case Relational = 'relational';
    case KeyValue = 'key_value';
    case Document = 'document';
    case Analytics = 'analytics';
}
