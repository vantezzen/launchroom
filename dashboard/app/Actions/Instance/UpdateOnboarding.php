<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Models\InstanceSettings;
use App\Queries\OnboardingChecklist;
use Illuminate\Support\Facades\DB;

/**
 * Skips or un-skips a first-run step, or dismisses / reopens the checklist
 * (docs/08 §3.1). Steps that are done stay done regardless.
 */
final class UpdateOnboarding
{
    public function handle(?bool $dismissed, ?string $skip, ?string $unskip): InstanceSettings
    {
        return DB::transaction(static function () use ($dismissed, $skip, $unskip): InstanceSettings {
            $settings = InstanceSettings::lockedForUpdate();
            $onboarding = $settings->onboarding ?? [];
            $skipped = array_values(array_intersect($onboarding['skipped'] ?? [], OnboardingChecklist::SKIPPABLE));

            if ($skip !== null && in_array($skip, OnboardingChecklist::SKIPPABLE, true) && ! in_array($skip, $skipped, true)) {
                $skipped[] = $skip;
            }

            if ($unskip !== null) {
                $skipped = array_values(array_diff($skipped, [$unskip]));
            }

            $onboarding['skipped'] = $skipped;

            if ($dismissed === true) {
                $onboarding['dismissedAt'] = now()->toIso8601ZuluString();
            } elseif ($dismissed === false) {
                unset($onboarding['dismissedAt']);
            }

            $settings->forceFill(['onboarding' => $onboarding])->save();

            return $settings;
        });
    }
}
