<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Enums\DaemonTaskType;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * POST /daemon/v1/tasks/lease (protocol/schema/v1/lease-request.json).
 */
final class LeaseRequest extends Data
{
    /**
     * @param  list<string>  $types
     */
    public function __construct(
        #[In(['launchroom.vantezzen.io/v1'])]
        public string $apiVersion,
        #[In(['LeaseRequest'])]
        public string $kind,
        #[Min(1), Max(16)]
        public int $max,
        #[Min(1)]
        public array $types,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return ['types.*' => ['string', Rule::enum(DaemonTaskType::class)]];
    }

    /**
     * @return list<DaemonTaskType>
     */
    public function taskTypes(): array
    {
        return array_values(array_unique(array_map(DaemonTaskType::from(...), $this->types), SORT_REGULAR));
    }
}
