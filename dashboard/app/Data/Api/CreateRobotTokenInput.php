<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Data\Attributes\PublicIdOf;
use App\Enums\ApiAbility;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Settings → Robot tokens → Create (docs/16 §1.4). The team is the current
 * team; without `project` the robot reaches every project, with `service`
 * (of that project) only that service.
 */
final class CreateRobotTokenInput extends Data
{
    /**
     * @param  list<string>  $abilities  ApiAbility values (ignored with full access)
     */
    public function __construct(
        #[Min(1), Max(80)]
        public string $name,
        #[In([7, 30, 90, 365])]
        public int $expiresInDays,
        public array $abilities = [],
        #[PublicIdOf('prj')]
        public ?string $project = null,
        #[PublicIdOf('svc')]
        public ?string $service = null,
        /** Every ability the team allows robots, including ones added later (D113). */
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
