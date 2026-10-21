<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\ApiOperation;
use App\Http\Api\OpenApi\OpenApiDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * The committed OpenAPI document (docs/16 §2.4). Public: it describes the
 * API, never instance data.
 */
final class OpenApiController extends Controller
{
    #[ApiOperation(id: 'getOpenApi', summary: 'This document', tag: 'Meta', description: 'The OpenAPI 3.1 document of this API (JSON).')]
    public function __invoke(): Response
    {
        return new Response(OpenApiDocument::committed(), 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
