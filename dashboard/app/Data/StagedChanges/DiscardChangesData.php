<?php

declare(strict_types=1);

namespace App\Data\StagedChanges;

use Spatie\LaravelData\Data;

/**
 * Discard staged changes: the change paths to drop, or all of them (null).
 */
final class DiscardChangesData extends Data
{
    /**
     * @param  list<string>|null  $paths
     */
    public function __construct(
        public ?array $paths = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'paths' => ['nullable', 'array', 'max:500'],
            'paths.*' => ['string', 'max:255', 'regex:/^[A-Za-z]+(?:\.[A-Za-z0-9_.-]+)*$/'],
        ];
    }
}
