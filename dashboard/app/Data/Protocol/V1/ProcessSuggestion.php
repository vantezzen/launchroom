<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * An extra process from the same image (queue worker, scheduler, …), argv.
 */
final class ProcessSuggestion extends Data
{
    /**
     * @param  list<string>  $args
     */
    public function __construct(
        #[Max(64)]
        public string $name,
        public array $args,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['args' => ['array', 'min:1', 'max:64'], 'args.*' => ['string', 'max:4096']];
    }
}
