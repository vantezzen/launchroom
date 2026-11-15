<?php

declare(strict_types=1);

use App\Models\Alerts\Alert;
use App\Models\Alerts\AlertChannel;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\BackupVerification;
use App\Models\Backups\DeletionRequest;
use App\Models\Backups\RestoreRun;
use App\Models\Backups\WalArchive;
use App\Models\ConfigAsCode\EnvironmentConfigFile;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Models\Git\GitRepository;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewShareLink;
use App\Models\RemoteAccess\RemoteAccess;
use App\Models\Security\ImageInventory;
use App\Models\Security\SecurityFinding;
use App\Models\Security\SecurityInvestigation;
use App\Models\Security\WafExclusion;
use App\Models\Sessions\DatabaseQuery;
use App\Models\Sessions\InteractiveSession;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Policies\Alerts\AlertChannelPolicy;
use App\Policies\Alerts\AlertPolicy;
use App\Policies\Backups\BackupDestinationPolicy;
use App\Policies\Backups\BackupRunPolicy;
use App\Policies\Backups\BackupSchedulePolicy;
use App\Policies\Backups\BackupVerificationPolicy;
use App\Policies\Backups\DeletionRequestPolicy;
use App\Policies\Backups\RestoreRunPolicy;
use App\Policies\Backups\WalArchivePolicy;
use App\Policies\ConfigAsCode\EnvironmentConfigFilePolicy;
use App\Policies\Dockerfiles\DockerfileGenerationPolicy;
use App\Policies\Git\GitConnectionPolicy;
use App\Policies\Git\GitHubAppPolicy;
use App\Policies\Git\GitHubInstallationPolicy;
use App\Policies\Git\GitHubRepositoryPolicy;
use App\Policies\Git\GitRepositoryPolicy;
use App\Policies\Previews\PreviewPolicy;
use App\Policies\Previews\PreviewShareLinkPolicy;
use App\Policies\RemoteAccess\RemoteAccessPolicy;
use App\Policies\Security\ImageInventoryPolicy;
use App\Policies\Security\SecurityFindingPolicy;
use App\Policies\Security\SecurityInvestigationPolicy;
use App\Policies\Security\WafExclusionPolicy;
use App\Policies\Sessions\DatabaseQueryPolicy;
use App\Policies\Sessions\InteractiveSessionPolicy;
use App\Policies\Transfers\DataExportRunPolicy;
use App\Policies\Transfers\DataImportRunPolicy;
use App\Policies\Transfers\DataTransferPolicy;
use Illuminate\Support\Facades\Gate;

// Sub-namespaced models get their policy from Laravel's App\Models\X\Y →
// App\Policies\X\YPolicy convention, with no Gate::policy() registrations.
test('every sub-namespaced model resolves its policy by convention', function (string $model, string $policy): void {
    expect(Gate::getPolicyFor($model))->toBeInstanceOf($policy);
})->with([
    [Alert::class, AlertPolicy::class],
    [AlertChannel::class, AlertChannelPolicy::class],
    [BackupDestination::class, BackupDestinationPolicy::class],
    [BackupRun::class, BackupRunPolicy::class],
    [BackupSchedule::class, BackupSchedulePolicy::class],
    [BackupVerification::class, BackupVerificationPolicy::class],
    [DeletionRequest::class, DeletionRequestPolicy::class],
    [RestoreRun::class, RestoreRunPolicy::class],
    [WalArchive::class, WalArchivePolicy::class],
    [EnvironmentConfigFile::class, EnvironmentConfigFilePolicy::class],
    [DockerfileGeneration::class, DockerfileGenerationPolicy::class],
    [GitConnection::class, GitConnectionPolicy::class],
    [GitHubApp::class, GitHubAppPolicy::class],
    [GitHubInstallation::class, GitHubInstallationPolicy::class],
    [GitHubRepository::class, GitHubRepositoryPolicy::class],
    [GitRepository::class, GitRepositoryPolicy::class],
    [Preview::class, PreviewPolicy::class],
    [PreviewShareLink::class, PreviewShareLinkPolicy::class],
    [RemoteAccess::class, RemoteAccessPolicy::class],
    [ImageInventory::class, ImageInventoryPolicy::class],
    [SecurityFinding::class, SecurityFindingPolicy::class],
    [SecurityInvestigation::class, SecurityInvestigationPolicy::class],
    [WafExclusion::class, WafExclusionPolicy::class],
    [DatabaseQuery::class, DatabaseQueryPolicy::class],
    [InteractiveSession::class, InteractiveSessionPolicy::class],
    [DataExportRun::class, DataExportRunPolicy::class],
    [DataImportRun::class, DataImportRunPolicy::class],
    [DataTransfer::class, DataTransferPolicy::class],
]);
