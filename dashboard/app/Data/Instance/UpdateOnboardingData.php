<?php

declare(strict_types=1);

namespace App\Data\Instance;

use App\Enums\Instance\OnboardingStep;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * Get started checklist: dismiss or reopen it, skip or un-skip a step.
 */
final class UpdateOnboardingData extends Data
{
    public function __construct(
        public ?bool $dismissed = null,
        public ?string $skip = null,
        public ?string $unskip = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'skip' => ['nullable', 'string', Rule::in(OnboardingStep::skippableValues())],
            'unskip' => ['nullable', 'string', Rule::in(OnboardingStep::skippableValues())],
        ];
    }
}
