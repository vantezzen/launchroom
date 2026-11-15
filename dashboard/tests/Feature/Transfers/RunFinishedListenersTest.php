<?php

declare(strict_types=1);

use App\Events\Backups\BackupRunFinished;
use App\Events\Transfers\ExportRunFinished;
use App\Events\Transfers\ImportRunFinished;
use App\Jobs\Moves\AdvanceServerMovesJob;
use App\Jobs\Transfers\AdvanceImportsJob;
use App\Models\Moves\ServerMove;
use App\Models\Server;
use Illuminate\Support\Facades\Queue;

test('a finished backup wakes waiting imports', function (): void {
    Queue::fake();

    event(new BackupRunFinished(1, null));

    Queue::assertPushed(AdvanceImportsJob::class);
});

test('finished backup, export and import runs wake an open server move, and only then', function (string $event): void {
    Queue::fake();

    event(new $event(1, null));
    Queue::assertNotPushed(AdvanceServerMovesJob::class);

    $source = Server::factory()->online()->create();
    $target = Server::factory()->online()->create(['team_id' => $source->team_id]);
    ServerMove::query()->create(['team_id' => $source->team_id, 'source_server_id' => $source->id, 'target_server_id' => $target->id]);

    event(new $event(1, null));
    Queue::assertPushed(AdvanceServerMovesJob::class, 1);
})->with([BackupRunFinished::class, ExportRunFinished::class, ImportRunFinished::class]);
