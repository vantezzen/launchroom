<?php

declare(strict_types=1);

use App\Http\Controllers\Moves\MoveReceiverController;
use App\Http\Middleware\SignResponse;
use App\Models\Moves\ControlPlaneMove;
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
    ->where(['controlPlaneMove' => PublicId::routePattern(ControlPlaneMove::publicIdPrefix()), 'index' => '[0-9]{1,7}'])
    ->group(function (): void {
        Route::post('claim', [MoveReceiverController::class, 'claim'])->name('move.receiver.claim');
        Route::get('{controlPlaneMove}', [MoveReceiverController::class, 'show'])->name('move.receiver.show');
        Route::get('{controlPlaneMove}/chunks/{index}', [MoveReceiverController::class, 'chunk'])->name('move.receiver.chunk');
        Route::get('{controlPlaneMove}/keys', [MoveReceiverController::class, 'keys'])->name('move.receiver.keys');
        Route::post('{controlPlaneMove}/complete', [MoveReceiverController::class, 'complete'])->name('move.receiver.complete');
        Route::post('{controlPlaneMove}/abort', [MoveReceiverController::class, 'abort'])->name('move.receiver.abort');
    });
