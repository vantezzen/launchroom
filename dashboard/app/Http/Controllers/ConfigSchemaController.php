<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ConfigAsCode\ConfigSchema;
use Symfony\Component\HttpFoundation\Response;

/**
 * The launchroom.json JSON Schema (docs/18 §2.2), public like the OpenAPI
 * document: no instance data, and editors of self-hosted users can point
 * `$schema` at their own dashboard.
 */
final class ConfigSchemaController extends Controller
{
    public function __invoke(): Response
    {
        return response()->file(ConfigSchema::path(), [
            'Content-Type' => 'application/schema+json',
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
