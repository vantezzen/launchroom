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
        /** How long the process needs to finish its work on SIGTERM (a default for the stop timeout, 1s to 5m). */
        #[Max(16)]
        public ?string $stopTimeout = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['args' => ['array', 'min:1', 'max:64'], 'args.*' => ['string', 'max:4096']];
    }
}
