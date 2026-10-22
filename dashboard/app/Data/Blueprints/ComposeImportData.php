<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use App\Support\Compose\ComposeYaml;
use Closure;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * "Import a Compose file" (docs/17 §2.1), for the preview and the import:
 * a pasted file or a repository branch to read it from, the user's `.env`
 * for interpolation, pasted `env_file`s, and the choices from the preview.
 * The server parses and translates it again on every request.
 */
final class ComposeImportData extends Data
{
    /**
     * @param  array<string, string>  $files  `env_file` path => content
     * @param  array<string, string>  $databases  Compose service name => managed|container
     */
    public function __construct(
        #[In(['paste', 'repository'])]
        public string $source = 'paste',
        #[Max(ComposeYaml::MAX_BYTES)]
        public ?string $compose = null,
        public ?string $repository = null,
        #[Max(255)]
        public ?string $branch = null,
        #[Max(65536)]
        public ?string $envFile = null,
        public array $files = [],
        public array $databases = [],
        public bool $convertBindMounts = true,
        #[Min(1), Max(100)]
        public ?string $name = null,
        public bool $deploy = true,
    ) {}

    /**
     * @return array<string, list<string|Closure>>
     */
    public static function rules(): array
    {
        return [
            'compose' => ['nullable', 'string', 'required_unless:source,repository'],
            'repository' => ['nullable', 'string', 'required_if:source,repository', 'regex:/^(?:ghr|gir)_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D'],
            'branch' => ['nullable', 'string', 'required_if:source,repository'],
            'files' => ['sometimes', 'array', 'max:10'],
            'files.*' => ['string', 'max:65536'],
            'databases' => ['sometimes', 'array', 'max:50'],
            'databases.*' => ['string', 'in:managed,container'],
        ];
    }

    /**
     * Never echo the file or the values back (validation errors, debugging).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['source' => $this->source, 'repository' => $this->repository, 'branch' => $this->branch, 'compose' => '[redacted]', 'envFile' => '[redacted]', 'files' => array_keys($this->files)];
    }
}
