<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Override;

/**
 * `throttle:N,M` with one budget per route. Laravel keys these limits by the
 * user (or IP) alone, so every `throttle:…` route shared one counter: typing
 * in a console (120/min) used up the budget of creating an API token
 * (10/min). Each limit now counts the requests to its own route, which is
 * what the numbers in routes/ mean (docs/09 R10). Named limiters
 * (`throttle:secret-reveal`) keep their own keys.
 */
final class ThrottleRequestsPerRoute extends ThrottleRequests
{
    /**
     * @param  Request  $request
     */
    #[Override]
    protected function resolveRequestSignature($request): string
    {
        $route = $request->route();
        $scope = $route instanceof Route ? $request->method().' '.$route->getDomain().'/'.$route->uri() : '';

        return hash('sha256', parent::resolveRequestSignature($request).'|'.$scope);
    }
}
