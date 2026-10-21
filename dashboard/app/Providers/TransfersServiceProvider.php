<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateDaemon;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Models\User;
use App\Policies\Transfers\DataExportRunPolicy;
use App\Policies\Transfers\DataImportRunPolicy;
use App\Policies\Transfers\DataTransferPolicy;
use App\Support\PublicId;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LogicException;

/**
 * Wiring for data import and export (D55, docs/14 §12): policies, the
 * team-scoped `{transfer}`, `{import}` and `{export}` bindings, rate
 * limiters and the web routes.
 */
final class TransfersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(DataTransfer::class, DataTransferPolicy::class);
        Gate::policy(DataImportRun::class, DataImportRunPolicy::class);
        Gate::policy(DataExportRun::class, DataExportRunPolicy::class);

        Route::bind('transfer', static fn (string $value): Model => self::resolve(DataTransfer::class, $value));
        Route::bind('import', static fn (string $value): Model => self::resolve(DataImportRun::class, $value));
        Route::bind('export', static fn (string $value): Model => self::resolve(DataExportRun::class, $value));

        // Browser and CLI chunks: 8 MiB each, per user.
        RateLimiter::for('transfer-chunks', static fn (Request $request): Limit => Limit::perMinute(600)->by('transfer-chunks:'.(is_scalar($id = $request->user()?->getAuthIdentifier()) ? (string) $id : (string) $request->ip())));
        // launchroomd's chunks, per server (docs/14 §12.2).
        // The new machine of a control-plane move (docs/14 §13.4): status polls and chunks.
        RateLimiter::for('move-receiver', static fn (Request $request): Limit => Limit::perMinute(1200)->by('move-receiver:'.$request->ip()));

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

    /**
     * @param  class-string<DataTransfer|DataImportRun|DataExportRun>  $class
     */
    private static function resolve(string $class, string $value): Model
    {
        $user = Auth::user();

        if (! $user instanceof User || ! PublicId::isValid($value, $class::publicIdPrefix())) {
            throw (new ModelNotFoundException)->setModel($class);
        }

        return $class::query()
            ->where('public_id', $value)
            ->whereIn('team_id', $user->teamIdsQuery())
            ->whereIn('service_instance_id', ServiceInstance::query()->select('id')->whereIn('team_id', $user->teamIdsQuery()))
            ->firstOrFail();
    }
}
