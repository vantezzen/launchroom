<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Transfers\CancelTransfer;
use App\Actions\Transfers\CompleteUpload;
use App\Actions\Transfers\CreateUpload;
use App\Actions\Transfers\StoreTransferChunk;
use App\Data\Transfers\ChunkReceiptData;
use App\Data\Transfers\Inputs\CreateTransferInput;
use App\Data\Transfers\TransferData;
use App\Enums\Transfers\TransferDirection;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Transfers\TransferController as WebTransferController;
use App\Models\Environment;
use App\Models\Service;
use App\Models\Transfers\DataTransfer;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Chunked, resumable uploads for `launchroom db import` (docs/14 §12.7).
 */
final class TransferController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'createTransfer', summary: 'Announce an upload to import', tag: 'Data', response: TransferData::class, request: CreateTransferInput::class, status: 201, description: 'Then send its chunks (0-based, chunkBytes each, the last one shorter) and complete it.')]
    public function store(Environment $environment, Service $service, CreateTransferInput $input, #[CurrentUser] User $user, CreateUpload $create): JsonResponse
    {
        $instance = $this->instance($environment, $service);
        Gate::authorize('create', [DataTransfer::class, $instance]);

        return ApiResponse::item(TransferData::fromTransfer($create->handle($instance, $input, $user)), 201);
    }

    #[ApiOperation(id: 'getTransfer', summary: 'An upload and the chunks received so far', tag: 'Data', response: TransferData::class)]
    public function show(DataTransfer $transfer): JsonResponse
    {
        Gate::authorize('view', $transfer);

        return ApiResponse::item(TransferData::fromTransfer($transfer));
    }

    #[ApiOperation(id: 'putTransferChunk', summary: 'Send one chunk of an upload', tag: 'Data', response: ChunkReceiptData::class, description: 'Raw body (application/octet-stream) with its SHA-256 in X-Chunk-Sha256. Sending a chunk again replaces it.')]
    public function chunk(Request $request, DataTransfer $transfer, int $index, StoreTransferChunk $store): JsonResponse
    {
        Gate::authorize('update', $transfer);
        abort_unless($transfer->direction === TransferDirection::Upload, 404);

        return ApiResponse::item($store->handle($transfer, $index, $request->getContent(), WebTransferController::chunkDigest($request)));
    }

    #[ApiOperation(id: 'completeTransfer', summary: 'Finish an upload: checks it and says what it is', tag: 'Data', response: TransferData::class)]
    public function complete(DataTransfer $transfer, CompleteUpload $complete): JsonResponse
    {
        Gate::authorize('update', $transfer);

        return ApiResponse::item(TransferData::fromTransfer($complete->handle($transfer)));
    }

    #[ApiOperation(id: 'deleteTransfer', summary: 'Cancel an upload and delete its chunks', tag: 'Data', status: 204)]
    public function destroy(DataTransfer $transfer, CancelTransfer $cancel): Response
    {
        Gate::authorize('delete', $transfer);
        abort_unless($transfer->direction === TransferDirection::Upload, 404);

        $cancel->handle($transfer);

        return ApiResponse::noContent();
    }
}
