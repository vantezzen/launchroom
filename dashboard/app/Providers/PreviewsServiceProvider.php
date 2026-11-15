<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Routing\TeamScopedBindings;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewShareLink;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for preview environments (docs/06 §5).
 */
final class PreviewsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        TeamScopedBindings::teamOwned([
            'preview' => Preview::class,
            'shareLink' => PreviewShareLink::class,
        ]);
    }
}
