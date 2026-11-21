<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Data\Attributes\PublicIdOf;
use App\Enums\ApiAbility;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Data;

/**
 * Approving a CLI sign-in: into which of the member's teams, with which
 * abilities and for how long.
 */
final class ApproveCliLoginInput extends Data
{
    /**
     * @param  list<string>  $abilities  ApiAbility values (ignored with full access)
     */
    public function __construct(
        #[PublicIdOf('team')]
        public string $team,
        public array $abilities = [],
        #[In([7, 30, 90, 365])]
        public int $expiresInDays = 90,
        /** Every ability the role allows, including ones added later (D113). */
        public bool $fullAccess = false,
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
