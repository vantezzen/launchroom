<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\ApiAbility;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Settings → API tokens → Create (docs/16 §1.3). The team is the current
 * team; `read` is always included.
 */
final class CreateApiTokenInput extends Data
{
    /**
     * @param  list<string>  $abilities  ApiAbility values
     */
    public function __construct(
        #[Min(1), Max(80)]
        public string $name,
        #[In([7, 30, 90, 365])]
        public int $expiresInDays,
        #[Min(1)]
        public array $abilities,
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
