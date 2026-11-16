<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Data\Instance\OnboardingStateData;
use App\Models\InstanceSettings;
use Illuminate\Support\Facades\DB;

/**
 * Closes the first-live card on the canvas for good (docs/08 §3.1): the
 * moment is shown once. Stored next to the checklist's other UI state.
 */
final class DismissFirstAppCard
{
    public function handle(): void
    {
        DB::transaction(static function (): void {
            $settings = InstanceSettings::lockedForUpdate();
            $onboarding = clone ($settings->onboarding ?? new OnboardingStateData);
            $onboarding->firstAppSeenAt ??= now()->toIso8601ZuluString();
            $settings->forceFill(['onboarding' => $onboarding])->save();
        });
    }
}
