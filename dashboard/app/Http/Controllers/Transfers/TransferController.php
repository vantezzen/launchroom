<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Actions\Transfers\CancelTransfer;
use App\Actions\Transfers\CompleteUpload;
use App\Actions\Transfers\StoreTransferChunk;
use App\Data\Transfers\ChunkReceiptData;
use App\Data\Transfers\TransferData;
use App\Enums\Transfers\TransferDirection;
use App\Http\Controllers\Controller;
use App\Models\Transfers\DataTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Chunked, resumable uploads from the browser (docs/14 §12.1).
 */
final class TransferController extends Controller
{
    public function show(DataTransfer $transfer): TransferData
    {
        Gate::authorize('view', $transfer);

        return TransferData::fromTransfer($transfer);
    }

    public function chunk(Request $request, DataTransfer $transfer, int $index, StoreTransferChunk $store): ChunkReceiptData
    {
        Gate::authorize('update', $transfer);
        abort_unless($transfer->direction === TransferDirection::Upload, 404);

        return $store->handle($transfer, $index, $request->getContent(), self::chunkDigest($request));
    }

    public function complete(DataTransfer $transfer, CompleteUpload $complete): TransferData
    {
        Gate::authorize('update', $transfer);

        return TransferData::fromTransfer($complete->handle($transfer));
    }

    public function destroy(DataTransfer $transfer, CancelTransfer $cancel): Response
    {
        Gate::authorize('delete', $transfer);
        abort_unless($transfer->direction === TransferDirection::Upload, 404);

        $cancel->handle($transfer);

        return response()->noContent();
    }

    /**
     * The chunk's SHA-256 (required, lowercase hex).
     */
    public static function chunkDigest(Request $request): string
    {
        $digest = strtolower((string) $request->header('X-Chunk-Sha256', ''));
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $digest) === 1, 422, 'Send the chunk’s SHA-256 in X-Chunk-Sha256.');

        return $digest;
    }
}
