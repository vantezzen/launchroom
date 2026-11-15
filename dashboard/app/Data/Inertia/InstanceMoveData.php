<?php

declare(strict_types=1);

namespace App\Data\Inertia;

use App\Models\InstanceSettings;
use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * Moving launchroom to a new server (docs/14 §13.4): writes are paused during
 * the hand-over (everyone sees it); after a move instance owners see the checklist.
 */
final class InstanceMoveData extends Data
{
    public function __construct(
        public bool $frozen,
        public bool $checklist,
    ) {}

    public static function for(User $user): ?self
    {
        $settings = InstanceSettings::current();
        $frozen = $settings->frozen_at !== null;
        $checklist = $user->is_instance_owner && $settings->move_checklist !== null;

        return $frozen || $checklist ? new self($frozen, $checklist) : null;
    }
}
