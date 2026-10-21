<?php

declare(strict_types=1);

namespace App\Http\Controllers\ApiTokens;

use App\Http\Api\OpenApi\OpenApiDocument;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The API reference (docs/16 §2.4): the committed OpenAPI document,
 * rendered by the docs page.
 */
final class ApiDocsController extends Controller
{
    public function __invoke(): Response
    {
        Gate::authorize('viewAny', ApiToken::class);

        return Inertia::render('docs/api', [
            'document' => OpenApiDocument::decoded(),
            'apiUrl' => url('/api/v1'),
        ]);
    }
}
