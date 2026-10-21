<?php

declare(strict_types=1);

use App\Http\Controllers\Transfers\DownloadController;
use App\Http\Controllers\Transfers\ServiceTransfersController;
use App\Http\Controllers\Transfers\TransferController;
use App\Support\PublicId;
use Illuminate\Support\Facades\Route;

/*
| Data import and export (D55, docs/14 §12). Loaded by
| App\Providers\TransfersServiceProvider with the `web` group. Every binding
| is team-scoped; chunks have their own limiter.
*/

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('transfers/services/{serviceInstance}', [ServiceTransfersController::class, 'show'])
        ->middleware('throttle:120,1')
        ->name('transfers.services.show');
    Route::post('transfers/services/{serviceInstance}/transfers', [ServiceTransfersController::class, 'storeTransfer'])
        ->middleware('throttle:30,1')
        ->name('transfers.services.transfers.store');
    Route::post('transfers/services/{serviceInstance}/inspect', [ServiceTransfersController::class, 'inspect'])
        ->middleware('throttle:20,1')
        ->name('transfers.services.inspect');
    Route::get('transfers/services/{serviceInstance}/inspect/{task}', [ServiceTransfersController::class, 'inspection'])
        ->where('task', PublicId::routePattern('tsk'))
        ->middleware('throttle:120,1')
        ->name('transfers.services.inspection');
    Route::post('transfers/services/{serviceInstance}/imports', [ServiceTransfersController::class, 'storeImport'])
        ->middleware('throttle:10,1')
        ->name('transfers.services.imports.store');
    Route::post('transfers/services/{serviceInstance}/exports', [ServiceTransfersController::class, 'storeExport'])
        ->middleware('throttle:10,1')
        ->name('transfers.services.exports.store');
    Route::post('transfers/services/{serviceInstance}/exports/{export}/link', [ServiceTransfersController::class, 'link'])
        ->middleware('throttle:20,1')
        ->name('transfers.services.exports.link');

    Route::get('transfers/{transfer}', [TransferController::class, 'show'])
        ->middleware('throttle:120,1')
        ->name('transfers.show');
    Route::put('transfers/{transfer}/chunks/{index}', [TransferController::class, 'chunk'])
        ->whereNumber('index')
        ->middleware('throttle:transfer-chunks')
        ->name('transfers.chunks');
    Route::post('transfers/{transfer}/complete', [TransferController::class, 'complete'])
        ->middleware('throttle:30,1')
        ->name('transfers.complete');
    Route::delete('transfers/{transfer}', [TransferController::class, 'destroy'])
        ->middleware('throttle:30,1')
        ->name('transfers.destroy');

    Route::get('transfers/download/{token}', DownloadController::class)
        ->where('token', 'lr_dl_[A-Za-z0-9_-]{43}')
        ->middleware('throttle:30,1')
        ->name('transfers.download');
});
