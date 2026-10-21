<?php

declare(strict_types=1);

namespace App\Data\Alerts;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Settings → Notifications "What we tell you about".
 */
final class AlertRulesInput extends Data
{
    /**
     * @param  list<AlertRuleInput>  $rules
     */
    public function __construct(
        #[DataCollectionOf(AlertRuleInput::class), Max(20)]
        public array $rules,
    ) {}
}
