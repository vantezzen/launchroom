<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Enums\DatabaseEngine;
use Spatie\LaravelData\Data;

/**
 * A database the import flow proposes, pre-checked, with the apps that
 * should connect to it and why ("DATABASE_URL in .env.example").
 */
final class SuggestedDatabaseData extends Data
{
    /**
     * @param  list<string>  $apps  root directories of the apps that use it
     */
    public function __construct(
        public DatabaseEngine $engine,
        public string $reason,
        public array $apps,
    ) {}
}
