<?php

declare(strict_types=1);

namespace App\Http\Controllers\Daemon;

use App\Actions\Transfers\StoreTransferChunk;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateDaemon;
use App\Models\Transfers\DataTransfer;
use App\Services\Transfers\DaemonTransfers;
use App\Services\Transfers\TransferStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Transfer chunks for launchroomd (docs/14 §12.2). GET answers chunk n of an
 * upload an import task on this server reads (the body is signed by
 * SignResponse like every 2xx body); PUT stores chunk n of an export task
 * on this server (covered by the request signature's content-digest). Only
 * transfers of runs whose task this server holds right now: else 404.
 */
final class TransferChunkController extends Controller
{
    public function show(Request $request, string $transfer, int $index, DaemonTransfers $transfers, TransferStore $store): Response
    {
        $server = AuthenticateDaemon::server($request);
        $gone = false;
        $readable = $transfers->readable($server, $transfer, $gone);

        if ($gone) {
            return new Response('', 410);
        }

        abort_if(! $readable instanceof DataTransfer, 404);
        abort_if($index < 0 || $index >= $readable->chunk_count, 422, 'chunk index out of range');

        return new Response($store->get($readable, $index), 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function store(Request $request, string $transfer, int $index, DaemonTransfers $transfers, StoreTransferChunk $store): Response
    {
        $server = AuthenticateDaemon::server($request);
        $gone = false;
        $writable = $transfers->writable($server, $transfer, $gone);

        if ($gone) {
            return new Response('', 410);
        }

        abort_if(! $writable instanceof DataTransfer, 404);
        $store->handle($writable, $index, $request->getContent());

        return new Response('', 204);
    }
}
