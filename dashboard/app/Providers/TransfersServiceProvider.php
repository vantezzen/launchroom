<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateDaemon;
use App\Http\Routing\TeamScopedBindings;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataCopyRun;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

/**
 * Wiring for data import and export (D55, docs/14 §12): the `{transfer}`,
 * `{import}`, `{export}` and `{copy}` bindings, rate limiters and the web routes.
 */
final class TransfersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The row and its service instance must both belong to the user's teams.
        foreach (['transfer' => DataTransfer::class, 'import' => DataImportRun::class, 'export' => DataExportRun::class] as $parameter => $class) {
            TeamScopedBindings::define($parameter, $class, static function (Builder $query, User $user): void {
                $query->whereIn('team_id', $user->teamIdsQuery())
                    ->whereIn('service_instance_id', ServiceInstance::query()->select('id')->whereIn('team_id', $user->teamIdsQuery()));
            });
        }

        // A copy and both of its services must belong to the user's teams (docs/14 §14).
        TeamScopedBindings::define('copy', DataCopyRun::class, static function (Builder $query, User $user): void {
            $query->whereIn('team_id', $user->teamIdsQuery())
                ->whereIn('target_service_instance_id', ServiceInstance::query()->select('id')->whereIn('team_id', $user->teamIdsQuery()));
        });

        // Browser and CLI chunks: 8 MiB each, per user.
        RateLimiter::for('transfer-chunks', static fn (Request $request): Limit => Limit::perMinute(600)->by('transfer-chunks:'.(is_scalar($id = $request->user()?->getAuthIdentifier()) ? (string) $id : (string) $request->ip())));
        // The new machine of a control-plane move (docs/14 §13.4): status polls and chunks.
        RateLimiter::for('move-receiver', static fn (Request $request): Limit => Limit::perMinute(1200)->by('move-receiver:'.$request->ip()));

        // launchroomd's chunks, per server (docs/14 §12.2).
        RateLimiter::for('daemon-transfers', static function (Request $request): Limit {
            try {
                $key = AuthenticateDaemon::server($request)->public_id;
            } catch (LogicException) {
                $key = (string) $request->ip();
            }

            return Limit::perMinute(1200)->by('daemon-transfers:'.$key);
        });

        $this->loadRoutesFrom(base_path('routes/transfers.php'));
    }
}
