<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Dismisses "This server is small" for one user (docs/08 §14), on every
 * page that shows it. Stored next to the checklist's other UI state.
 */
final class DismissSmallServerHint
{
    public function handle(User $user): void
    {
        DB::transaction(static function () use ($user): void {
            $settings = InstanceSettings::lockedForUpdate();
            $onboarding = $settings->onboarding ?? [];
            $dismissed = array_values(array_filter($onboarding['smallServerHintDismissed'] ?? [], is_int(...)));

            if (! in_array($user->id, $dismissed, true)) {
                $dismissed[] = $user->id;
            }

            $onboarding['smallServerHintDismissed'] = $dismissed;
            $settings->forceFill(['onboarding' => $onboarding])->save();
        });
    }
}
