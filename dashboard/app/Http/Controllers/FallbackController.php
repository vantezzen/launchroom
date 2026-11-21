<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Unknown dashboard paths, inside the web group so the 404 page knows who
 * is signed in (docs/08 §24). Machine-facing prefixes never reach it
 * (RouteFamily::browserFallbackPattern()).
 */
final class FallbackController
{
    public function __invoke(): never
    {
        throw new NotFoundHttpException;
    }
}
