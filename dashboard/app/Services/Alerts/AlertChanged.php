<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Events\CanvasChanged;
use App\Models\Alerts\Alert;
use App\Models\Environment;
use Illuminate\Support\Facades\DB;

/**
 * Tells viewers of an environment's canvas that one of its alerts opened,
 * escalated or resolved (docs/15 §3.6), so the node's attention chip
 * updates live. The broadcast carries no data and goes out after commit.
 */
final class AlertChanged
{
    public static function broadcastFor(Alert $alert): void
    {
        if ($alert->environment_id === null) {
            return;
        }

        DB::afterCommit(static function () use ($alert): void {
            $environment = Environment::query()->whereKey($alert->environment_id)->value('public_id');

            if (is_string($environment)) {
                broadcast(new CanvasChanged($environment));
            }
        });
    }
}
