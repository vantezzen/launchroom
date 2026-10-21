<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Routing\Previews\PreviewRouteBindings;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewShareLink;
use App\Policies\Previews\PreviewPolicy;
use App\Policies\Previews\PreviewShareLinkPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for preview environments (docs/06 §5).
 */
final class PreviewsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Preview::class, PreviewPolicy::class);
        Gate::policy(PreviewShareLink::class, PreviewShareLinkPolicy::class);

        PreviewRouteBindings::register();
    }
}
