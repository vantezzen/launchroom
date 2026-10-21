<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Transfers\CreateDownloadLink;
use App\Actions\Transfers\StartExport;
use App\Data\Transfers\DownloadLinkData;
use App\Data\Transfers\ExportRunData;
use App\Data\Transfers\Inputs\StartExportInput;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Service;
use App\Models\Transfers\DataExportRun;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Exports of databases and volumes, live or from a backup (docs/14 §12.7).
 */
final class ExportController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'createExport', summary: 'Prepare a download of the data, now or from a backup', tag: 'Data', response: ExportRunData::class, request: StartExportInput::class, status: 201, description: 'Follow it with GET /exports/{export}; once ready, make a link and download it.')]
    public function store(Request $request, Environment $environment, Service $service, StartExportInput $input, #[CurrentUser] User $user, StartExport $start): JsonResponse
    {
        $instance = $this->instance($environment, $service);
        Gate::authorize('create', [DataExportRun::class, $instance]);

        return ApiResponse::item(ExportRunData::fromRun($start->handle($instance, $input, $user, $this->auditActor($request, $user))), 201);
    }

    #[ApiOperation(id: 'getExport', summary: 'An export and whether it is ready', tag: 'Data', response: ExportRunData::class)]
    public function show(DataExportRun $export): JsonResponse
    {
        Gate::authorize('view', $export);

        return ApiResponse::item(ExportRunData::fromRun($export));
    }

    #[ApiOperation(id: 'createExportLink', summary: 'A one-use download link (5 minutes, this token’s user)', tag: 'Data', response: DownloadLinkData::class)]
    public function link(DataExportRun $export, #[CurrentUser] User $user, CreateDownloadLink $link): JsonResponse
    {
        Gate::authorize('download', $export);

        ['token' => $token, 'expiresAt' => $expiresAt] = $link->handle($export, $user);

        return ApiResponse::item(new DownloadLinkData(route('api.downloads.show', $token), $expiresAt->toIso8601String()));
    }
}
