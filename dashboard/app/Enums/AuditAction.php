<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Audited actions (docs/07 `audit_events`). Values are stable identifiers
 * stored in the hash chain: never rename a case value.
 */
enum AuditAction: string
{
    case SetupTokenCreated = 'instance.setup_token_created';
    case InstanceOwnerCreated = 'instance.owner_created';
    case InstanceTeamClaimed = 'instance.team_claimed';
    case OwnerTwoFactorReset = 'instance.owner_two_factor_reset';
    case TeamCreated = 'team.created';
    case MemberInvited = 'team.member_invited';
    case InvitationRevoked = 'team.invitation_revoked';
    case InvitationAccepted = 'team.invitation_accepted';
    case MemberRoleChanged = 'team.member_role_changed';
    case MemberRemoved = 'team.member_removed';
    case SigningKeyCreated = 'instance.signing_key_created';
    case ServerEnrollmentCreated = 'server.enrollment_created';
    case ServerEnrollmentCanceled = 'server.enrollment_canceled';
    case ServerEnrolled = 'server.enrolled';
    case ServerApproved = 'server.approved';
    case ServerRevoked = 'server.revoked';
    case VolumeDeletionConfirmed = 'server.volume_deletion_confirmed';
    case MeshEnabled = 'instance.mesh_enabled';
    case MeshOwnTailscaleConfigured = 'instance.mesh_own_tailscale_configured';
    case MeshNodeRemoved = 'server.mesh_node_removed';
    case ProjectCreated = 'project.created';
    case ProjectImported = 'project.imported';
    case ProjectDeleted = 'project.deleted';
    case ProjectRenamed = 'project.renamed';
    case ProjectSecretsPolicyChanged = 'project.secrets_policy_changed';
    case ProjectIconChanged = 'project.icon_changed';
    case EnvironmentCreated = 'environment.created';
    case EnvironmentDeployed = 'environment.deployed';
    case ChangesDiscarded = 'environment.changes_discarded';
    case EnvironmentUpdated = 'environment.updated';
    case EnvironmentDeleted = 'environment.deleted';
    case EnvironmentReverted = 'environment.reverted';
    case ChangesPromoted = 'environment.changes_promoted';
    case AutoDeployResumed = 'environment.auto_deploy_resumed';
    case DeploymentPromoted = 'deployment.promoted';
    case ServiceCreated = 'service.created';
    case ServiceRemoved = 'service.removed';
    case ServiceRenamed = 'service.renamed';
    case VariableCreated = 'variable.created';
    case VariableUpdated = 'variable.updated';
    case VariableDeleted = 'variable.deleted';
    case VariablesImported = 'variable.imported';
    case SecretRevealed = 'variable.secret_revealed';
    case ConnectionCreated = 'connection.created';
    case ConnectionDeleted = 'connection.deleted';
    case DomainAdded = 'domain.added';
    case DomainRemoved = 'domain.removed';
    case DomainWwwRedirectChanged = 'domain.www_redirect_changed';
    case AppsDomainChanged = 'instance.apps_domain_changed';
    // Settings → Dashboard domain (docs/05 §4.1, D43).
    case DashboardDomainStarted = 'instance.dashboard_domain_started';
    case DashboardDomainCancelled = 'instance.dashboard_domain_cancelled';
    case DashboardDomainSwitched = 'instance.dashboard_domain_switched';
    case DashboardDomainReverted = 'instance.dashboard_domain_reverted';
    case DashboardDomainReset = 'instance.dashboard_domain_reset';
    case DeploymentRolledBack = 'deployment.rolled_back';
    case DeploymentRedeployed = 'deployment.redeployed';
    case DeploymentCancelled = 'deployment.cancelled';
    case VariablesRawEdited = 'variable.raw_edited';
    case GitHubAppCreated = 'github.app_created';
    case GitHubAppDisconnected = 'github.app_disconnected';
    case GitHubInstallationLinked = 'github.installation_linked';
    case GitConnectionCreated = 'git.connection_created';
    case GitConnectionAuthorized = 'git.connection_authorized';
    case GitConnectionDisconnected = 'git.connection_disconnected';
    case GitConnectionPrivateNetworkAllowed = 'git.connection_private_network_allowed';
    case GitHostKeysConfirmed = 'git.host_keys_confirmed';
    case GitWebhookSecretRevealed = 'git.webhook_secret_revealed';
    case BackupDestinationCreated = 'backup.destination_created';
    case BackupDestinationUpdated = 'backup.destination_updated';
    case BackupDestinationDeleted = 'backup.destination_deleted';
    case BackupDestinationPrivateNetworkAllowed = 'backup.destination_private_network_allowed';
    case BackupScheduleUpdated = 'backup.schedule_updated';
    case BackupStarted = 'backup.started';
    case RestoreStarted = 'backup.restore_started';
    // Data import and export (D55, docs/14 §12.5).
    case DataImportStarted = 'data.import_started';
    case DataImportFinished = 'data.import_finished';
    case DataImportSourceChecked = 'data.import_source_checked';
    case DataExportStarted = 'data.export_started';
    case DataExportDownloaded = 'data.export_downloaded';
    case RestoreSwapped = 'backup.restore_swapped';
    case BackupVolumeUpdated = 'backup.volume_updated';
    case FinalBackupSkipped = 'backup.final_backup_skipped';
    case PointInTimeRecoveryUpdated = 'backup.point_in_time_updated';
    case BackupVerificationStarted = 'backup.verification_started';
    case ServiceVolumeAdded = 'service.volume_added';
    case ServerReplaced = 'server.replaced';
    case RecoveryFinished = 'instance.recovery_finished';
    case RecoveryPassphraseSet = 'backup.recovery_passphrase_set';
    case RecoveryKitDownloaded = 'backup.recovery_kit_downloaded';
    case RecoveryKitConfirmed = 'backup.recovery_kit_confirmed';
    case PreviewSettingsUpdated = 'preview.settings_updated';
    case PreviewStrategyChanged = 'preview.strategy_changed';
    case PreviewApproved = 'preview.approved';
    case PreviewDestroyed = 'preview.destroyed';
    case PreviewShareLinkCreated = 'preview.share_link_created';
    case PreviewShareLinkRevoked = 'preview.share_link_revoked';
    case PreviewBypassRotated = 'preview.bypass_rotated';
    case ControlPlaneUpdateStarted = 'instance.update_started';
    case ControlPlaneUpdateFinished = 'instance.update_finished';
    case FleetUpdateStarted = 'instance.fleet_update_started';
    case FleetUpdateFinished = 'instance.fleet_update_finished';
    case UpdatePreferencesChanged = 'instance.update_preferences_changed';
    case DnsProviderChanged = 'instance.dns_provider_changed';
    case SharedEdgeChanged = 'server.shared_edge_changed';
    case DomainHstsChanged = 'domain.hsts_changed';
    case RegistryStorageConfigured = 'instance.registry_storage_configured';
    case RegistryStoragePrivateNetworkAllowed = 'instance.registry_storage_private_network_allowed';
    case RegistryStorageRemoved = 'instance.registry_storage_removed';
    case RegistryConfigWritten = 'instance.registry_config_written';
    case ServerSettingsUpdated = 'server.settings_updated';
    case ServerRolesUpdated = 'server.roles_updated';
    case ServerAppearanceUpdated = 'server.appearance_updated';
    case ReplicasRescheduled = 'server.replicas_rescheduled';
    case ServiceDegraded = 'service.degraded';
    // Interactive sessions (docs/03 §11, docs/09 R33).
    case ConsoleSessionStarted = 'session.console_started';
    case ConsoleSessionEnded = 'session.console_ended';
    case DataSessionStarted = 'session.data_started';
    case DataSessionEnded = 'session.data_ended';
    case DatabaseStatementExecuted = 'database.statement_executed';
    case SessionRecordingViewed = 'session.recording_viewed';
    case ConsoleRecordingChanged = 'team.console_recording_changed';
    case MemberPermissionsChanged = 'team.member_permissions_changed';
    case ApiTokenCreated = 'api_token.created';
    case ApiTokenRevoked = 'api_token.revoked';
    case CliLoginApproved = 'api_token.cli_login_approved';
    case CliLoginDenied = 'api_token.cli_login_denied';
    case EnvExported = 'variable.env_exported';
    case DeploymentRequested = 'deployment.requested';
    // Alerts and notifications (docs/15 §3).
    case AlertChannelCreated = 'alerts.channel_created';
    case AlertChannelUpdated = 'alerts.channel_updated';
    case AlertChannelDeleted = 'alerts.channel_deleted';
    // Audit log forwarding (D50, docs/09 R34).
    case AuditSinkCreated = 'audit.sink_created';
    case AuditSinkUpdated = 'audit.sink_updated';
    case AuditSinkDeleted = 'audit.sink_deleted';
    case AlertRulesUpdated = 'alerts.rules_updated';
    // Config as code (docs/18).
    case ConfigFileApplied = 'config_file.applied';
    case ConfigFileInvalid = 'config_file.invalid';
    case ConfigFileExported = 'config_file.exported';
    case ConfigFileSettingsUpdated = 'config_file.settings_updated';
    // Generate a Dockerfile (docs/20).
    case AiProviderUpdated = 'ai_provider.updated';
    case DockerfileGenerationTeamChanged = 'team.dockerfile_generation_changed';
    case DockerfileGenerationStarted = 'dockerfile_generation.started';
    case DockerfileGenerationUsed = 'dockerfile_generation.used';
    case DockerfileGenerationPullRequestOpened = 'dockerfile_generation.pull_request_opened';
    // Client certificates for servers (mTLS, D50, docs/03 §4.3).
    case ClientCertificatesChanged = 'instance.client_certificates_changed';
    case ClientCertificateIssued = 'server.client_certificate_issued';
    case ClientCertificatesRevoked = 'server.client_certificates_revoked';
    case ClientCertificateReissueAllowed = 'server.client_certificate_reissue_allowed';
    // Move to a new server (D56, docs/14 §13).
    case ServerMoveStarted = 'server.move_started';
    case ServerMoveItemFinished = 'server.move_item_finished';
    case ServerMoveItemRetried = 'server.move_item_retried';
    case ServerMoveCancelled = 'server.move_cancelled';
    case ServerMoveCompleted = 'server.move_completed';
    case ControlPlaneMoveStarted = 'instance.control_plane_move_started';
    case ControlPlaneMoveClaimed = 'instance.control_plane_move_claimed';
    case ControlPlaneMoveApproved = 'instance.control_plane_move_approved';
    case ControlPlaneMoveCancelled = 'instance.control_plane_move_cancelled';
    case ControlPlaneHandoverStarted = 'instance.control_plane_handover_started';
    case ControlPlaneHandoverAborted = 'instance.control_plane_handover_aborted';
    case ControlPlaneHandedOver = 'instance.control_plane_handed_over';
    case ControlPlaneTakenOver = 'instance.control_plane_taken_over';
    case ControlPlaneMoveFinished = 'instance.control_plane_move_finished';
}
