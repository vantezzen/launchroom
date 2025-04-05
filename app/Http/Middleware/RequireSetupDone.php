<?php

namespace App\Http\Middleware;

use App\Settings\InstanceSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSetupDone
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $settings = app(InstanceSettings::class);
        if (! $settings->setup_done) {
            return redirect()->route('setup.index');
        }

        return $next($request);
    }
}
