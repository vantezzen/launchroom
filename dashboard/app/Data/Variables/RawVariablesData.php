<?php

declare(strict_types=1);

namespace App\Data\Variables;

use Spatie\LaravelData\Data;

/**
 * The raw `.env` editor's content on save (empty removes every variable).
 */
final class RawVariablesData extends Data
{
    public function __construct(
        public ?string $content,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['content' => ['present', 'nullable', 'string', 'max:'.EnvImportData::MAX_BYTES]];
    }
}
