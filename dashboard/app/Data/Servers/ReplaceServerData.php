<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Replace a dead server (docs/14 §5.3): the replacement and the stateful
 * service instances to restore from their latest backups.
 */
final class ReplaceServerData extends Data
{
    /**
     * @param  list<string>  $restore
     */
    public function __construct(
        #[Max(40)]
        public string $replacement,
        public array $restore = [],
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'restore' => ['array', 'max:500'],
            'restore.*' => ['string', 'max:40'],
        ];
    }
}
