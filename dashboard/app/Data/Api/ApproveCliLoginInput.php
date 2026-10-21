<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\ApiAbility;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Approving a CLI sign-in: into which of the member's teams, with which
 * abilities and for how long.
 */
final class ApproveCliLoginInput extends Data
{
    /**
     * @param  list<string>  $abilities  ApiAbility values
     */
    public function __construct(
        #[Regex('/^team_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public string $team,
        #[Min(1)]
        public array $abilities,
        #[In([7, 30, 90, 365])]
        public int $expiresInDays = 90,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return ['abilities.*' => ['string', Rule::in(ApiAbility::values())]];
    }

    /**
     * @return list<ApiAbility>
     */
    public function abilityList(): array
    {
        return array_values(array_unique(array_map(ApiAbility::from(...), $this->abilities), SORT_REGULAR));
    }
}
