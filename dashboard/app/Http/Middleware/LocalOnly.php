<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Development-only pages (the design sheets): 404 outside the local
 * environment.
 */
final readonly class LocalOnly
{
    public function __construct(private Application $app) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->app->environment('local'), 404);

        return $next($request);
    }
}
