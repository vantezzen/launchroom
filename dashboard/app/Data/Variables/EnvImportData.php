<?php

declare(strict_types=1);

namespace App\Data\Variables;

use App\Enums\VariableType;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * A pasted `.env` (docs/06 §3.4): previewed, then imported, optionally only
 * some keys (`only`) and with a type chosen per key (`types`).
 */
final class EnvImportData extends Data
{
    public const int MAX_BYTES = 524288;

    /**
     * @param  list<string>|null  $only
     * @param  array<array-key, string>  $types
     */
    public function __construct(
        #[Max(self::MAX_BYTES)]
        public string $content,
        public ?array $only = null,
        public array $types = [],
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'only' => ['nullable', 'array', 'max:1000'],
            'only.*' => ['string', 'max:256'],
            'types' => ['nullable', 'array', 'max:1000'],
            'types.*' => ['string', Rule::enum(VariableType::class)],
        ];
    }

    /**
     * @return array<string, VariableType>
     */
    public function typesByKey(): array
    {
        $types = [];

        foreach ($this->types as $key => $type) {
            if (is_string($key)) {
                $types[$key] = VariableType::from($type);
            }
        }

        return $types;
    }
}
