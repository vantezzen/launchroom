<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Support\Protocol\Grammar;
use SensitiveParameter;
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
     * @param  array<string, string|null>  $secrets  required secrets pasted on import (RAILS_MASTER_KEY): name => value (empty: skipped)
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
        /** Created as secret variables of the app in production; never flashed or logged (SensitiveInput). */
        #[SensitiveParameter]
        public array $secrets = [],
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'secrets' => ['sometimes', 'array', 'max:8'],
            'secrets.*' => ['nullable', 'string', 'max:4096'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'rootDirectory' => $this->rootDirectory, 'secrets' => array_fill_keys(array_keys($this->secrets), '[redacted]')];
    }
}
