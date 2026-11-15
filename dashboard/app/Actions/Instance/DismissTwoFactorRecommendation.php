<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Data\Instance\OnboardingStateData;
use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Not now" on Get started's two-factor recommendation (docs/README D33:
 * recommended, never required). Per user; Settings → Security keeps offering
 * it. Stored next to the checklist's other UI state.
 */
final class DismissTwoFactorRecommendation
{
    public function handle(User $user): void
    {
        DB::transaction(static function () use ($user): void {
            $settings = InstanceSettings::lockedForUpdate();
            $onboarding = clone ($settings->onboarding ?? new OnboardingStateData);
            $dismissed = $onboarding->twoFactorDismissed;

            if (! in_array($user->id, $dismissed, true)) {
                $dismissed[] = $user->id;
            }

            $onboarding->twoFactorDismissed = $dismissed;
            $settings->forceFill(['onboarding' => $onboarding])->save();
        });
    }
}
