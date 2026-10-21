<?php

declare(strict_types=1);

use App\Http\Controllers\Moves\MoveReceiverController;
use App\Http\Middleware\SignResponse;
use App\Support\PublicId;
use Illuminate\Support\Facades\Route;

/*
| The new machine of a control-plane move (docs/14 §13.4, `install.sh
| --move`). Registered from bootstrap/app.php under /move/v1 without the web
| group: no session, cookies or CSRF. The token's secret authenticates
| (Bearer, compared by hash); every 2xx answer is signed with the control
| plane's key (SignResponse), which the new machine pinned from the token.
*/

Route::middleware([SignResponse::class, 'throttle:move-receiver'])
    ->where(['move' => PublicId::routePattern('cpm'), 'index' => '[0-9]{1,7}'])
    ->group(function (): void {
        Route::post('claim', [MoveReceiverController::class, 'claim'])->name('move.receiver.claim');
        Route::get('{move}', [MoveReceiverController::class, 'show'])->name('move.receiver.show');
        Route::get('{move}/chunks/{index}', [MoveReceiverController::class, 'chunk'])->name('move.receiver.chunk');
        Route::get('{move}/keys', [MoveReceiverController::class, 'keys'])->name('move.receiver.keys');
        Route::post('{move}/complete', [MoveReceiverController::class, 'complete'])->name('move.receiver.complete');
        Route::post('{move}/abort', [MoveReceiverController::class, 'abort'])->name('move.receiver.abort');
    });
