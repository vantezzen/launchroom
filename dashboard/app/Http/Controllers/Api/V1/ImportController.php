<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Transfers\StartImport;
use App\Data\Transfers\ImportRunData;
use App\Data\Transfers\Inputs\StartImportInput;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Service;
use App\Models\Transfers\DataImportRun;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Imports into databases and volumes (docs/14 §12.7).
 */
final class ImportController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'createImport', summary: 'Import an uploaded file or a connection URL', tag: 'Data', response: ImportRunData::class, request: StartImportInput::class, status: 201, description: 'Replacing needs `confirmation` (the service slug) and takes a safety snapshot first. Follow it with GET /imports/{import}.')]
    public function store(Request $request, Environment $environment, Service $service, StartImportInput $input, #[CurrentUser] User $user, StartImport $start): JsonResponse
    {
        $instance = $this->instance($environment, $service);
        Gate::authorize('create', [DataImportRun::class, $instance]);

        return ApiResponse::item(ImportRunData::fromRun($start->handle($instance, $input, $user, $this->auditActor($request, $user))), 201);
    }

    #[ApiOperation(id: 'getImport', summary: 'An import and its outcome', tag: 'Data', response: ImportRunData::class)]
    public function show(DataImportRun $import): JsonResponse
    {
        Gate::authorize('view', $import);

        return ApiResponse::item(ImportRunData::fromRun($import));
    }
}
