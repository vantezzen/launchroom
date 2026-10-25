<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Security\ImageInventory;
use App\Models\Security\SecurityFinding;
use App\Models\Security\SecurityInvestigation;
use App\Models\Security\WafExclusion;
use App\Models\User;
use App\Policies\Security\ImageInventoryPolicy;
use App\Policies\Security\SecurityFindingPolicy;
use App\Policies\Security\SecurityInvestigationPolicy;
use App\Policies\Security\WafExclusionPolicy;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the security center (D63, docs/22): policies, the
 * team-scoped `{finding}` and `{wafExclusion}` bindings, routes.
 */
final class SecurityServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string<SecurityFinding|WafExclusion>> */
    private const array BINDINGS = [
        'finding' => SecurityFinding::class,
        'wafExclusion' => WafExclusion::class,
    ];

    public function boot(): void
    {
        Gate::policy(SecurityFinding::class, SecurityFindingPolicy::class);
        Gate::policy(SecurityInvestigation::class, SecurityInvestigationPolicy::class);
        Gate::policy(WafExclusion::class, WafExclusionPolicy::class);
        Gate::policy(ImageInventory::class, ImageInventoryPolicy::class);

        foreach (self::BINDINGS as $parameter => $class) {
            Route::bind($parameter, static function (string $value) use ($class): Model {
                $user = Auth::user();

                if (! $user instanceof User || ! PublicId::isValid($value, $class::publicIdPrefix())) {
                    throw (new ModelNotFoundException)->setModel($class);
                }

                return $class::query()
                    ->where('public_id', $value)
                    ->whereIn('team_id', $user->teamIdsQuery())
                    ->firstOrFail();
            });
        }

        $this->loadRoutesFrom(base_path('routes/security.php'));
    }
}
