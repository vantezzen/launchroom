<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Transfers\OpenDownload;
use App\Http\Api\ApiOperation;
use App\Http\Controllers\Controller;
use App\Http\Transfers\TransferDownloadResponse;
use App\Models\User;
use App\Services\Transfers\TransferStore;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/v1/downloads/{token}: streams a ready export once (docs/14 §12.7).
 */
final class DownloadController extends Controller
{
    #[ApiOperation(id: 'download', summary: 'Download an export with a one-use link', tag: 'Data', description: 'The file itself (application/octet-stream), once, for the user who made the link, within 5 minutes.')]
    public function __invoke(Request $request, string $token, #[CurrentUser] User $user, OpenDownload $open, TransferStore $store): StreamedResponse
    {
        $transfer = $open->handle($token, $user, $this->auditActor($request, $user), true);
        Gate::authorize('download', $transfer);

        return TransferDownloadResponse::make($transfer, $store);
    }
}
