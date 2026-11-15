<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * An app of the repository to create as a service when importing.
 */
final class ImportAppData extends Data
{
    /**
     * @param  list<ImportProcessData>  $processes  suggested processes to add as workers of this app
     */
    public function __construct(
        #[Min(1), Max(63)]
        public string $name,
        #[Max(1024), Regex('#^(?:'.Grammar::REL_PATH_BODY.')?$#D')]
        public ?string $rootDirectory = null,
        #[Between(1, 65535)]
        public ?int $port = null,
        /** Detected framework (display only; picks Laravel-style connection variables). */
        #[Max(40)]
        public ?string $framework = null,
        #[Max(6)]
        public array $processes = [],
    ) {}
}
