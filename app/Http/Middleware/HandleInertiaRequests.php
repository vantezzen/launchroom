<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $currentTeam = $request->route('team');
        if (is_string($currentTeam)) {
            $currentTeam = $request->user()?->teams()->where('slug', $currentTeam)->firstOrFail();
        }
        optional($currentTeam)->load('projects');

        $currentProject = $request->route('project');
        if (is_string($currentProject)) {
            $currentProject = $currentTeam->projects()->where('slug', $currentProject)->firstOrFail();
        }
        optional($currentProject)->load(['environments', 'environments.deployments']);

        $currentEnvironment = $request->route('environment');
        if (is_string($currentEnvironment)) {
            $currentEnvironment = $currentProject->environments()->findOrFail($currentEnvironment);
        }
        optional($currentEnvironment)->load(['deployments', 'services']);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
                'teams' => $request->user()?->teams()->with('projects')->get(),
            ],

            'currentTeam' => $currentTeam,
            'currentProject' => $currentProject,
            'currentEnvironment' => $currentEnvironment,
        ];
    }
}
