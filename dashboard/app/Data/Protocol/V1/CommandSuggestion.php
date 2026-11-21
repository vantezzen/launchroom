<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * A standard one-off command of the detected framework (protocol
 * CommandSuggestion, D105): shown in the Commands tab, argv only.
 */
final class CommandSuggestion extends Data
{
    /**
     * @param  list<string>  $args
     */
    public function __construct(
        #[Max(63)]
        public string $name,
        #[Max(64)]
        public string $label,
        #[Max(200)]
        public string $description,
        public array $args,
        /** Changes data or state: the dashboard asks before it runs. */
        public bool $danger = false,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['args' => ['array', 'min:1', 'max:64'], 'args.*' => ['string', 'max:4096']];
    }
}
