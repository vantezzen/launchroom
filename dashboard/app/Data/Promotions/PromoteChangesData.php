<?php

declare(strict_types=1);

namespace App\Data\Promotions;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * "Stage in target" (docs/06 §6.1): the selected items (conflicts included:
 * selecting one takes the source's side) and the services whose images are
 * promoted too.
 */
final class PromoteChangesData extends Data
{
    /**
     * @param  list<PromoteItemInput>  $items
     * @param  list<string>  $images  service public ids
     */
    public function __construct(
        #[Regex('/^env_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public string $from,
        #[Regex('/^env_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public string $to,
        #[Max(1000)]
        public array $items = [],
        #[Max(200)]
        public array $images = [],
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'images.*' => ['string', 'regex:/^svc_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D'],
        ];
    }
}
