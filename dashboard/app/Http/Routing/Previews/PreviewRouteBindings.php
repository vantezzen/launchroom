<?php

declare(strict_types=1);

namespace App\Http\Routing\Previews;

use App\Models\Previews\Preview;
use App\Models\Previews\PreviewShareLink;
use App\Models\User;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Team-scoped route bindings for previews (docs/09 R7): well-formed public
 * id and a row of one of the user's teams, else 404.
 */
final class PreviewRouteBindings
{
    /** @var array<string, class-string<Preview|PreviewShareLink>> */
    private const array TEAM_OWNED = [
        'preview' => Preview::class,
        'shareLink' => PreviewShareLink::class,
    ];

    public static function register(): void
    {
        foreach (self::TEAM_OWNED as $parameter => $class) {
            Route::bind($parameter, static function (string $value) use ($class): Preview|PreviewShareLink {
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
    }
}
