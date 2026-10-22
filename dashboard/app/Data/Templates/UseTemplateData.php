<?php

declare(strict_types=1);

namespace App\Data\Templates;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * The guided setup of a template (docs/17 §4): the project name (new
 * projects), the template's inputs, and the repository and branch for
 * stacks built from your code.
 */
final class UseTemplateData extends Data
{
    /**
     * @param  array<string, string>  $inputs  input id => value
     */
    public function __construct(
        #[Min(1), Max(100)]
        public ?string $name = null,
        public array $inputs = [],
        public ?string $repository = null,
        #[Max(255)]
        public ?string $branch = null,
        public bool $deploy = true,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'inputs' => ['sometimes', 'array', 'max:6'],
            'inputs.*' => ['nullable', 'string', 'max:255'],
            'repository' => ['nullable', 'string', 'regex:/^(?:ghr|gir)_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D'],
            'branch' => ['nullable', 'string', 'required_with:repository'],
        ];
    }
}
