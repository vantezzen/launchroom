<?php

declare(strict_types=1);

use App\Actions\Transfers\SweepTransfers;
use App\Enums\DaemonFeature;
use App\Enums\Permission;
use App\Enums\TeamRole;
use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\ImportRunStatus;
use App\Enums\Transfers\TransferStatus;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Services\Transfers\SourceUrl;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\Transfers;

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    $this->store = Transfers::useTempStore();
});

test('statuses change only along their transition tables', function (): void {
    expect(ImportRunStatus::WaitingForSafetyBackup->canTransitionTo(ImportRunStatus::Holding))->toBeTrue()
        ->and(ImportRunStatus::Importing->canTransitionTo(ImportRunStatus::Cancelled))->toBeFalse()
        ->and(ImportRunStatus::Succeeded->isTerminal())->toBeTrue()
        ->and(ExportRunStatus::Ready->canTransitionTo(ExportRunStatus::Failed))->toBeFalse()
        ->and(TransferStatus::Expired->isTerminal())->toBeTrue();

    expect(fn () => ImportRunStatus::Succeeded->assertCanTransitionTo(ImportRunStatus::Importing))->toThrow(InvalidStatusTransition::class);
});

test('only owners and admins may import and export', function (TeamRole $role, bool $allowed): void {
    expect($role->can(Permission::ManageBackups))->toBe($allowed);
})->with([
    'owner' => [TeamRole::Owner, true],
    'admin' => [TeamRole::Admin, true],
    'developer' => [TeamRole::Developer, false],
    'viewer' => [TeamRole::Viewer, false],
]);

test('connection URLs are checked by engine and labelled without credentials', function (): void {
    $url = SourceUrl::parse('postgresql://app:pw@db.example.com:6432/umami?sslmode=require', 'postgres');
    expect($url->label)->toBe('postgresql://db.example.com:6432/umami')
        ->and($url->host)->toBe('db.example.com')
        ->and(SourceUrl::parse('mongodb+srv://u:p@cluster0.example.net/app', 'mongodb')->label)->toBe('mongodb+srv://cluster0.example.net/app');

    foreach ([['postgres://u:p@db.example.com', 'postgres'], ['mysql://u:p@db.example.com/app', 'postgres'], ["postgres://u:p@db.example.com/app\nx", 'postgres'], ['postgres:///app', 'postgres']] as [$bad, $engine]) {
        expect(fn (): SourceUrl => SourceUrl::parse($bad, $engine))->toThrow(DomainRuleViolation::class);
    }
});

test('the sweep expires idle uploads, keeps ones an import reads and removes orphan folders', function (): void {
    [$team, $server, , $postgres] = Backups::runningPostgres();
    $server->forceFill(['daemon_features' => [DaemonFeature::DataTransfer->value]])->save();
    $owner = memberOf($team, TeamRole::Owner);
    [$idle] = Transfers::upload($this, $owner, $postgres, Transfers::pgCustom('15.8', '15.8', 2048), 'idle.dump');
    [$used] = Transfers::upload($this, $owner, $postgres, Transfers::pgCustom('15.8', '15.8', 2048), 'used.dump');
    $this->postJson(route('transfers.services.imports.store', $postgres), ['transfer' => $used->public_id])->assertSuccessful();
    @mkdir("{$this->store}/dtr_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ", 0700, true);
    touch("{$this->store}/dtr_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ", time() - 3600);

    $this->travel(25)->hours();
    touch("{$this->store}/{$idle->public_id}", time() - 3600);
    resolve(SweepTransfers::class)->handle();

    expect($idle->refresh()->status)->toBe(TransferStatus::Expired)
        ->and(is_dir("{$this->store}/{$idle->public_id}"))->toBeFalse()
        ->and($used->refresh()->status)->toBe(TransferStatus::Ready)
        ->and(DataImportRun::query()->sole()->status)->toBe(ImportRunStatus::Importing)
        ->and(is_dir("{$this->store}/dtr_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ"))->toBeFalse()
        ->and(DataTransfer::query()->count())->toBe(2);
});
