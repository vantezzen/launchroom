<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Enums\DatabaseEngine;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * A database to create with the import, connected to the listed apps.
 */
final class ImportDatabaseData extends Data
{
    /**
     * @param  list<string|null>  $connectTo  root directories of the apps to connect
     *                                        (the repository root arrives as null: empty
     *                                        strings are converted by the middleware)
     */
    public function __construct(
        public DatabaseEngine $engine,
        #[Min(1), Max(63)]
        public string $name,
        #[Max(20)]
        public array $connectTo = [],
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'connectTo.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
