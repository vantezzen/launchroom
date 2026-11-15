<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Actions\Transfers\CancelTransfer;
use App\Actions\Transfers\CompleteUpload;
use App\Actions\Transfers\StoreTransferChunk;
use App\Data\Transfers\ChunkReceiptData;
use App\Data\Transfers\TransferData;
use App\Data\Transfers\UploadedChunk;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
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
        RouteScope::upload($transfer);

        $chunk = UploadedChunk::fromRequest($request);

        return $store->handle($transfer, $index, $chunk->body, $chunk->sha256);
    }

    public function complete(DataTransfer $transfer, CompleteUpload $complete): TransferData
    {
        Gate::authorize('update', $transfer);

        return TransferData::fromTransfer($complete->handle($transfer));
    }

    public function destroy(DataTransfer $transfer, CancelTransfer $cancel): Response
    {
        Gate::authorize('delete', $transfer);
        RouteScope::upload($transfer);

        $cancel->handle($transfer);

        return response()->noContent();
    }
}
