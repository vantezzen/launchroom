<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Actions\Transfers\OpenDownload;
use App\Http\Controllers\Controller;
use App\Http\Transfers\TransferDownloadResponse;
use App\Models\User;
use App\Services\Transfers\TransferStore;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /transfers/download/{token}: a one-use, short-lived link for the
 * signed-in user who made it (docs/14 §12.5).
 */
final class DownloadController extends Controller
{
    public function __invoke(Request $request, string $token, #[CurrentUser] User $user, OpenDownload $open, TransferStore $store): StreamedResponse
    {
        $transfer = $open->handle($token, $user, $this->auditActor($request, $user), false);
        Gate::authorize('download', $transfer);

        return TransferDownloadResponse::make($transfer, $store);
    }
}
