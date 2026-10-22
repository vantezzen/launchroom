declare namespace App {
namespace Data {
namespace Activity {
export type ActivityEntryData = {
id: string,
kind: string,
at: string,
actor: string | null,
title: string,
detail: string | null,
environmentId: string | null,
environmentName: string | null,
href: string | null,
projectId: string | null,
projectName: string | null,
status: string | null,
sha: string | null,
severity: string | null,
};
export type ActivityFiltersData = {
project: string | null,
kind: string | null,
};
}
namespace Alerts {
export type AlertChannelData = {
id: string,
kind: App.Enums.Alerts.AlertChannelKind,
name: string,
hint: string | null,
recipients: string[],
enabled: boolean,
minSeverity: App.Enums.Alerts.AlertSeverity,
lastDeliveryAt: string | null,
lastDeliveryOk: boolean | null,
lastError: string | null,
canManage: boolean,
};
export type AlertChannelInput = {
kind: App.Enums.Alerts.AlertChannelKind,
name: string | null,
recipients: string[] | null,
secretUrl: string | null,
minSeverity: App.Enums.Alerts.AlertSeverity,
};
export type AlertChannelUpdateInput = {
name: string | null,
enabled: boolean | null,
minSeverity: App.Enums.Alerts.AlertSeverity | null,
recipients: string[] | null,
secretUrl: string | null,
rotateSecret: boolean,
};
export type AlertRuleData = {
rule: App.Enums.Alerts.AlertRule,
label: string,
description: string,
group: string,
enabled: boolean,
threshold: number | null,
thresholdMin: number | null,
thresholdMax: number | null,
thresholdUnit: string | null,
duration: number | null,
durationMin: number | null,
durationMax: number | null,
};
export type AlertRuleInput = {
rule: App.Enums.Alerts.AlertRule,
enabled: boolean,
threshold: number | null,
duration: number | null,
};
export type AlertRulesInput = {
rules: App.Data.Alerts.AlertRuleInput[],
};
export type AlertSummaryData = {
id: string,
rule: App.Enums.Alerts.AlertRule,
severity: App.Enums.Alerts.AlertSeverity,
status: App.Enums.Alerts.AlertStatus,
title: string,
summary: string,
href: string | null,
openedAt: string,
resolvedAt: string | null,
};
}
namespace Api {
export type ApiAbilityOptionData = {
value: App.Enums.ApiAbility,
label: string,
description: string,
available: boolean,
default: boolean,
};
export type ApiCommitData = {
sha: string,
shortSha: string,
message: string | null,
author: string | null,
ref: string | null,
};
export type ApiFailureData = {
class: App.Enums.DeploymentFailureClass | null,
message: string | null,
};
export type ApiTeamData = {
id: string,
name: string,
role: App.Enums.TeamRole | null,
};
export type ApiTeamOptionData = {
id: string,
name: string,
role: App.Enums.TeamRole,
current: boolean,
abilities: App.Data.Api.ApiAbilityOptionData[],
};
export type ApiTokenData = {
id: string,
name: string,
abilities: App.Enums.ApiAbility[],
client: App.Enums.ApiTokenClient,
teamId: string,
teamName: string,
expiresAt: string | null,
lastUsedAt: string | null,
lastUsedIp: string | null,
createdAt: string,
};
export type ApiUserData = {
name: string,
email: string,
};
export type ApproveCliLoginInput = {
team: string,
abilities: string[],
expiresInDays: number,
};
export type ClaimCliTokenInput = {
deviceCode: string,
};
export type CliLoginRequestData = {
userCode: string,
status: App.Enums.CliLoginStatus,
expired: boolean,
clientName: string,
hostname: string | null,
version: string | null,
ip: string | null,
createdAt: string,
expiresAt: string,
};
export type CliSessionData = {
deviceCode: string,
userCode: string,
verificationUrl: string,
verificationUrlComplete: string,
expiresIn: number,
interval: number,
};
export type CliTokenData = {
token: string,
expiresAt: string,
team: App.Data.Api.ApiTeamData,
abilities: App.Enums.ApiAbility[],
user: App.Data.Api.ApiUserData,
};
export type ConfigContentInput = {
content: string,
};
export type CreateApiTokenInput = {
name: string,
expiresInDays: number,
abilities: string[],
};
export type CreateDeploymentInput = {
service: string,
ref: string | null,
sha: string | null,
redeploy: boolean,
};
export type CreateDomainInput = {
hostname: string,
redirectTo: string | null,
};
export type CreateVariableInput = {
key: string,
value: string,
type: App.Enums.VariableType,
targets?: App.Data.Variables.VariableTargetData[],
};
export type CreatedApiTokenData = {
token: App.Data.Api.ApiTokenData,
plainText: string,
};
export type DeployEnvironmentInput = {
message: string | null,
stage: boolean,
};
export type DeployEnvironmentResultData = {
revision: number,
deployments: App.Data.Api.DeploymentResource[],
};
export type DeploymentResource = {
id: string,
environmentId: string,
serviceId: string,
serviceName: string,
status: App.Enums.DeploymentStatus,
finished: boolean,
trigger: App.Enums.DeploymentTrigger,
commit: App.Data.Api.ApiCommitData | null,
failure: App.Data.Api.ApiFailureData | null,
queuedAt: string,
startedAt: string | null,
finishedAt: string | null,
dashboardUrl: string,
};
export type DomainResource = {
id: string,
hostname: string,
kind: App.Enums.DomainKind,
redirectTo: string | null,
certStatus: App.Enums.CertStatus | null,
certExpiresAt: string | null,
hsts: boolean,
};
export type EnvQuery = {
secrets: boolean,
};
export type EnvironmentRefData = {
id: string,
name: string,
slug: string,
kind: App.Enums.EnvironmentKind,
};
export type EnvironmentResource = {
id: string,
projectId: string,
name: string,
slug: string,
kind: App.Enums.EnvironmentKind,
revision: number | null,
autoDeployPaused: boolean,
dashboardUrl: string,
};
export type ExportConfigQuery = {
revision: number | null,
};
export type ListDeploymentsQuery = {
service: string | null,
limit: number,
cursor: string | null,
};
export type LogCursorQuery = {
after: number,
};
export type MeData = {
token: App.Data.Api.ApiTokenData,
user: App.Data.Api.ApiUserData,
team: App.Data.Api.ApiTeamData,
};
export type ProjectResource = {
id: string,
name: string,
slug: string,
createdAt: string,
environments: App.Data.Api.EnvironmentRefData[],
dashboardUrl: string,
};
export type ResolvedEnvData = {
variables: Record<string, string>,
withheld: string[],
revision: number | null,
};
export type ServerResource = {
id: string,
name: string,
status: App.Enums.ServerStatus,
roles: App.Enums.ServerRole[],
isControlPlane: boolean,
publicIpv4: string | null,
publicIpv6: string | null,
meshIp: string | null,
daemonVersion: string | null,
lastSeenAt: string | null,
labels: Record<string, string>,
};
export type ServiceResource = {
id: string,
environmentId: string,
name: string,
slug: string,
kind: App.Enums.ServiceKind,
status: string,
activeDeployment: App.Data.Api.DeploymentResource | null,
latestDeployment: App.Data.Api.DeploymentResource | null,
urls: string[],
dashboardUrl: string,
volumes: App.Data.Transfers.TransferVolumeData[],
};
export type StartCliSessionInput = {
clientName: string,
hostname: string | null,
version: string | null,
};
export type UpdateVariableInput = {
key?: string,
value?: string,
type?: App.Enums.VariableType,
};
export type VariableResource = {
id: string,
key: string,
type: App.Enums.VariableType,
value: string | null,
targets: App.Data.Variables.VariableTargetData[],
connection: boolean,
editable: boolean,
};
export type VariableValueData = {
key: string,
value: string,
};
}
namespace Audit {
export type AuditActor = {
type: App.Enums.ActorType,
id: number | null,
ip: string | null,
userAgent: string | null,
token: string | null,
};
export type AuditChainVerification = {
valid: boolean,
checked: number,
firstInvalidId: number | null,
problem: App.Enums.Audit.AuditChainProblem | null,
message: string | null,
checkpointId: number | null,
checkpoints: number,
verifiedUpTo: string | null,
lastEventId: number | null,
incremental: boolean,
};
export type AuditEntryData = {
action: App.Enums.AuditAction,
actor: App.Data.Audit.AuditActor,
teamId: number | null,
subjectType: string | null,
subjectId: string | null,
meta: Record<string, string | number | boolean | null | string[]>,
};
export type AuditSinkInput = {
kind: App.Enums.Audit.AuditSinkKind,
name: string | null,
host: string | null,
port: number | null,
caCertificate: string | null,
secretUrl: string | null,
endpoint: string | null,
bucket: string | null,
region: string | null,
prefix: string | null,
accessKeyId: string | null,
secretAccessKey: string | null,
retentionDays: number | null,
lockMode: string | null,
allowPrivateNetwork: boolean,
};
export type AuditSinkUpdateInput = {
name: string | null,
enabled: boolean | null,
rotateSecret: boolean,
};
}
namespace Backups {
export type BackupDayData = {
date: string,
status: string,
};
export type BackupDestinationData = {
id: string,
name: string,
scope: App.Enums.Backups.DestinationScope,
endpoint: string,
bucket: string,
region: string | null,
prefix: string,
allowPrivateNetwork: boolean,
isDefault: boolean,
status: App.Enums.Backups.DestinationStatus,
lastCheckedAt: string | null,
lastError: string | null,
scheduleCount: number,
canManage: boolean,
};
export type BackupDestinationInput = {
name: string,
endpoint: string,
bucket: string,
region: string | null,
prefix: string,
accessKeyId: string | null,
secretAccessKey: string | null,
repositoryPassword: string | null,
allowPrivateNetwork: boolean,
makeDefault: boolean,
};
export type BackupProtectionData = {
scheduleId: string,
days: App.Data.Backups.BackupDayData[],
lastSucceededAt: string | null,
lastSizeBytes: number | null,
};
export type BackupRunData = {
id: string,
reason: App.Enums.Backups.BackupReason,
reasonLabel: string,
status: App.Enums.Backups.BackupRunStatus,
snapshotId: string | null,
shortSnapshotId: string | null,
sizeBytes: number | null,
server: string | null,
error: string | null,
createdAt: string | null,
startedAt: string | null,
finishedAt: string | null,
subject: App.Enums.Backups.BackupSubject,
durationMs: number | null,
};
export type BackupScheduleData = {
id: string,
subject: App.Enums.Backups.BackupSubject,
label: string,
projectName: string | null,
environmentName: string | null,
serviceId: string | null,
destinationId: string,
destinationName: string,
cron: string,
retention: App.Data.Backups.RetentionData,
retentionSummary: string,
enabled: boolean,
nextRunAt: string | null,
health: App.Data.Backups.ServiceBackupStatusData,
canManage: boolean,
cronDescription: string | null,
};
export type BackupScheduleInput = {
cron: string,
retention: App.Data.Backups.RetentionData,
enabled: boolean,
destination: string | null,
};
export type BackupTrendPointData = {
id: string,
subject: App.Enums.Backups.BackupSubject,
status: App.Enums.Backups.BackupRunStatus,
at: string,
sizeBytes: number | null,
durationMs: number | null,
};
export type BackupVerificationCheckData = {
name: string,
passed: boolean,
detail: string | null,
};
export type BackupVerificationData = {
id: string,
status: App.Enums.Backups.VerificationStatus,
subject: App.Enums.Backups.BackupSubject,
shortSnapshotId: string,
snapshotTakenAt: string | null,
checks: App.Data.Backups.BackupVerificationCheckData[],
tables: number | null,
rows: number | null,
files: number | null,
bytes: number | null,
restoredTo: string | null,
durationMs: number | null,
error: string | null,
createdAt: string | null,
finishedAt: string | null,
};
export type BackupVolumeData = {
id: string,
mountPath: string,
consistency: App.Enums.Backups.VolumeConsistency,
server: string | null,
updateUrl: string,
};
export type DeletionRequestData = {
id: string,
subjectType: string,
subjectName: string,
status: App.Enums.Backups.DeletionRequestStatus,
error: string | null,
runs: {
service: string,
volume: string | null,
status: string,
error: string | null,
}[],
createdAt: string | null,
canRetry: boolean,
canSkip: boolean,
urls: {
retry: string,
skip: string,
},
};
export type DestinationUsageData = {
destinationId: string,
lastBackupAt: string | null,
databaseBytes: number,
volumeBytes: number,
controlPlaneBytes: number,
};
export type PointInTimeData = {
supported: boolean,
enabled: boolean,
keepDays: number,
state: App.Enums.Backups.WalArchiveState | null,
lastArchivedAt: string | null,
pendingSegments: number,
message: string | null,
windowStart: string | null,
windowEnd: string | null,
nextBaseBackupAt: string | null,
canConfigure: boolean,
serverReady: boolean,
updateUrl: string,
};
export type RecoveryKitStatusData = {
destinationConfigured: boolean,
passphraseSet: boolean,
passphraseSetAt: string | null,
kitDownloadedAt: string | null,
kitConfirmedAt: string | null,
needsAttention: boolean,
};
export type RestoreRunData = {
id: string,
mode: App.Enums.Backups.RestoreMode,
status: App.Enums.Backups.RestoreRunStatus,
shortSnapshotId: string,
targetServiceId: string | null,
targetServiceName: string | null,
error: string | null,
createdAt: string | null,
finishedAt: string | null,
swapUrl: string | null,
pointInTime: boolean,
requestedTime: string | null,
restoredTo: string | null,
};
export type RetentionData = {
keepLast: number,
keepHourly: number,
keepDaily: number,
keepWeekly: number,
keepMonthly: number,
keepWithinHours: number,
};
export type ServiceBackupStatusData = {
status: App.Enums.Backups.BackupHealth,
lastSuccessfulAt: string | null,
nextRunAt: string | null,
lastError: string | null,
};
export type ServiceBackupsData = {
serviceInstanceId: string,
serviceName: string,
confirmationText: string,
subject: App.Enums.Backups.BackupSubject | null,
engine: string | null,
supported: boolean,
restoreSupported: boolean,
destinationName: string | null,
schedule: App.Data.Backups.BackupScheduleData | null,
status: App.Data.Backups.ServiceBackupStatusData,
snapshots: App.Data.Backups.SnapshotData[],
listingStatus: App.Enums.Backups.SnapshotListingStatus | null,
listingFetchedAt: string | null,
listingError: string | null,
runs: App.Data.Backups.BackupRunData[],
restores: App.Data.Backups.RestoreRunData[],
canBackup: boolean,
canRestoreAsNew: boolean,
canRestoreInPlace: boolean,
urls: {
backup: string,
refresh: string,
restore: string,
self: string,
verify: string,
},
volumes: App.Data.Backups.BackupVolumeData[],
canConfigure: boolean,
deletion: App.Data.Backups.DeletionRequestData | null,
pointInTime: App.Data.Backups.PointInTimeData | null,
verification: App.Data.Backups.BackupVerificationData | null,
canVerify: boolean,
trend: App.Data.Backups.BackupTrendPointData[],
};
export type SnapshotData = {
id: string,
shortId: string,
time: string,
sizeBytes: number | null,
reason: string | null,
source: App.Enums.Backups.SnapshotSource,
volumeId: string | null,
mountPath: string | null,
};
}
namespace Blueprints {
export type BlueprintBuild = {
context: string,
dockerfile: string | null,
};
export type BlueprintConnection = {
to: string,
style: App.Enums.ConnectionStyle,
};
export type BlueprintDatabase = {
engine: App.Enums.DatabaseEngine,
version: string,
};
export type BlueprintEdgeData = {
from: string,
to: string,
type: 'connection' | 'process',
};
export type BlueprintNote = {
kind: App.Enums.BlueprintNoteKind,
message: string,
field: string | null,
line: number | null,
};
export type BlueprintPreviewData = {
services: App.Data.Blueprints.BlueprintServicePreviewData[],
edges: App.Data.Blueprints.BlueprintEdgeData[],
cpus: number,
memoryMb: number,
volumes: number,
sharedVariables: number,
};
export type BlueprintScaling = {
replicas: number | null,
cpus: number | null,
memoryMb: number | null,
};
export type BlueprintService = {
key: string,
name: string,
kind: App.Enums.ServiceKind,
logo: string | null,
image: string | null,
build: App.Data.Blueprints.BlueprintBuild | null,
database: App.Data.Blueprints.BlueprintDatabase | null,
parent: string | null,
port: number | null,
public: boolean,
domain: string | null,
entrypoint: string[] | null,
startCommand: string[] | null,
healthcheck: App.Data.Services.HealthcheckSettings | null,
scaling: App.Data.Blueprints.BlueprintScaling | null,
capabilities: string[],
volumes: string[],
variables: App.Data.Blueprints.BlueprintVariable[],
connections: App.Data.Blueprints.BlueprintConnection[],
notes: App.Data.Blueprints.BlueprintNote[],
};
export type BlueprintServicePreviewData = {
key: string,
name: string,
kind: App.Enums.ServiceKind,
logo: string,
subtitle: string,
group: 'public' | 'app' | 'data',
port: number | null,
public: boolean,
replicas: number,
cpus: number,
memoryMb: number,
volumes: number,
variables: App.Data.Blueprints.BlueprintVariablePreviewData[],
notes: App.Data.Blueprints.BlueprintNote[],
choice: App.Data.Blueprints.ServiceChoiceData | null,
};
export type BlueprintVariable = {
key: string,
value: string,
secret: boolean,
};
export type BlueprintVariablePreviewData = {
key: string,
secret: boolean,
generated: boolean,
reference: boolean,
};
export type ComposeImportData = {
source: string,
compose: string | null,
repository: string | null,
branch: string | null,
envFile: string | null,
files: Record<string, string>,
databases: Record<string, string>,
convertBindMounts: boolean,
name: string | null,
};
export type ComposeInputData = {
name: string,
status: 'provided' | 'generated' | 'missing',
required: boolean,
secret: boolean,
message: string | null,
};
export type ComposePreviewData = {
preview: App.Data.Blueprints.BlueprintPreviewData | null,
notes: App.Data.Blueprints.BlueprintNote[],
inputs: App.Data.Blueprints.ComposeInputData[],
missingFiles: string[],
skipped: App.Data.Blueprints.SkippedServiceData[],
suggestedName: string | null,
path: string | null,
error: string | null,
errorLine: number | null,
};
export type ProjectBlueprint = {
name: string,
services: App.Data.Blueprints.BlueprintService[],
sharedVariables: App.Data.Blueprints.BlueprintVariable[],
version: number,
};
export type ServiceChoiceData = {
service: string,
engine: App.Enums.DatabaseEngine | null,
managed: boolean | null,
bindMounts: number,
};
export type SkippedServiceData = {
name: string,
reason: string,
line: number | null,
needsRepository: boolean,
};
}
namespace Canvas {
export type AddDomainData = {
hostname: string,
redirectTo: string | null,
};
export type AddServiceData = {
name: string,
kind: App.Enums.ServiceKind,
repository: string | null,
branch: string | null,
rootDirectory: string | null,
image: string | null,
port: number | null,
engine: App.Enums.DatabaseEngine | null,
version: string | null,
parentServiceId: string | null,
startCommand: string[] | null,
position: App.Data.Canvas.CanvasPositionData | null,
connectTo: string[] | null,
};
export type AdvancedServiceData = {
serviceId: string,
instanceId: string,
environmentId: string,
networkName: string,
imageRepository: string | null,
image: string | null,
activeDeploymentId: string | null,
workloads: App.Data.Canvas.WorkloadData[],
capabilityOptions: App.Data.Canvas.CapabilityOptionData[],
};
export type CanvasEdgeData = {
id: string,
type: string,
source: string,
target: string,
variables: string[],
mode: App.Enums.ConnectionMode | null,
style: App.Enums.ConnectionStyle | null,
staged: boolean,
};
export type CanvasLayoutData = {
positions: Record<string, App.Data.Canvas.CanvasPositionData>,
};
export type CanvasNodeData = {
id: string,
instanceId: string,
name: string,
slug: string,
kind: App.Enums.ServiceKind,
logo: string,
subtitle: string,
status: App.Data.Canvas.NodeStatusData,
domain: string | null,
privateAddress: string | null,
replicas: number,
volume: string | null,
server: string | null,
staged: App.Enums.ChangeEffect | null,
removed: boolean,
enabled: boolean,
parentId: string | null,
position: App.Data.Canvas.CanvasPositionData | null,
backup: App.Data.Backups.ServiceBackupStatusData | null,
placementIssue: App.Data.Canvas.PlacementIssueData | null,
};
export type CanvasPositionData = {
x: number,
y: number,
};
export type CanvasThumbnailData = {
nodes: App.Data.Canvas.CanvasThumbnailNodeData[],
edges: App.Data.Canvas.CanvasThumbnailEdgeData[],
aspectRatio: number,
positioned: boolean,
};
export type CanvasThumbnailEdgeData = {
source: string,
target: string,
type: string,
};
export type CanvasThumbnailNodeData = {
id: string,
kind: App.Enums.ServiceKind,
logo: string,
x: number,
y: number,
name: string | null,
};
export type CapabilityOptionData = {
name: string,
label: string,
description: string,
};
export type ConnectionPreviewData = {
mode: App.Enums.ConnectionMode,
style: App.Enums.ConnectionStyle,
styles: App.Enums.ConnectionStyle[],
modes: App.Enums.ConnectionMode[],
variables: App.Data.Canvas.ConnectionVariableData[],
error: string | null,
};
export type ConnectionVariableData = {
key: string,
value: string,
};
export type CreateConnectionData = {
from: string,
to: string,
mode: App.Enums.ConnectionMode,
style: App.Enums.ConnectionStyle,
};
export type DatabaseEngineOptionData = {
engine: App.Enums.DatabaseEngine,
label: string,
versions: string[],
defaultVersion: string,
tagline: string,
};
export type DeployDayData = {
date: string,
total: number,
failed: number,
};
export type DetectedPlanData = {
builder: string | null,
builderReason: string | null,
framework: string | null,
frameworkVersion: string | null,
provider: string | null,
packageManager: string | null,
runtime: string | null,
runtimeSource: string | null,
packages: App.Data.Protocol.V1.ResolvedPackage[],
installCommand: string | null,
buildCommand: string | null,
startCommand: string | null,
port: number | null,
healthPath: string | null,
releaseCommand: string | null,
railpackVersion: string | null,
presets: App.Data.Protocol.V1.AppliedPreset[],
buildVariables: string[],
runtimeVariableCount: number,
envReferences: string[],
missingVariables: string[],
warnings: string[],
planJson: Record<string, any> | null,
next: App.Data.Protocol.V1.NextBuildInfo | null,
};
export type DnsRecordData = {
type: string,
name: string,
value: string,
};
export type DomainData = {
id: string,
hostname: string,
kind: App.Enums.DomainKind,
redirectTo: string | null,
certificate: App.Data.Domains.CertificateData | null,
dnsRecords: App.Data.Canvas.DnsRecordData[],
staged: boolean,
canDelete: boolean,
verification: App.Data.Domains.DnsCheckData | null,
wwwRedirect: boolean,
cnameTarget: string | null,
canRedirectWww: boolean,
hsts: boolean,
canSetHsts: boolean,
};
export type EdgeServerData = {
id: string,
name: string,
status: App.Enums.ServerStatus,
device: App.Data.Servers.DeviceData | null,
};
export type EnvironmentData = {
id: string,
name: string,
slug: string,
kind: App.Enums.EnvironmentKind,
branch: string | null,
tagPattern: string | null,
autoDeployPaused: boolean,
};
export type EnvironmentSummaryData = {
id: string,
name: string,
kind: App.Enums.EnvironmentKind,
services: number,
status: App.Data.Canvas.NodeStatusData,
};
export type NodeStatusData = {
state: App.Enums.CanvasNodeState,
label: string,
since: string | null,
deploymentId: string | null,
};
export type PlacementIssueData = {
kind: string,
message: string,
serverId: string | null,
since: string,
};
export type PlacementPreviewServerData = {
id: string,
eligible: boolean,
reason: string | null,
planned: number,
};
export type PlacementServerOptionData = {
id: string,
name: string,
status: App.Enums.ServerStatus,
labels: Record<string, string>,
platform: string | null,
eligible: boolean,
reason: string | null,
replicas: number,
planned: number,
device: App.Data.Servers.DeviceData | null,
location: App.Data.Servers.LocationData | null,
cpus: number | null,
memoryMb: number | null,
reservedMemoryMb: number,
allocatedMemoryMb: number,
allocatedCpus: number,
allowPreviews: boolean,
allowProduction: boolean,
};
export type PlanPreviewData = {
status: string,
plan: App.Data.Canvas.DetectedPlanData | null,
message: string | null,
suggestions: App.Data.Projects.PlanSuggestionsData | null,
};
export type PlanStatusData = {
status: string,
commitSha: string | null,
branch: string | null,
finishedAt: string | null,
error: string | null,
};
export type ProjectAbilitiesData = {
manage: boolean,
connect: boolean,
deploy: boolean,
};
export type ProjectData = {
id: string,
name: string,
slug: string,
colorSlot: number,
iconUrl: string | null,
};
export type ProjectLastDeploymentData = {
id: string,
environmentId: string,
serviceName: string,
status: App.Enums.DeploymentStatus,
message: string | null,
author: string | null,
sha: string | null,
createdAt: string,
authorAvatarUrl: string | null,
};
export type ProjectLinkData = {
id: string,
name: string,
iconUrl: string | null,
};
export type ProjectSummaryData = {
id: string,
name: string,
slug: string,
environments: App.Data.Canvas.EnvironmentSummaryData[],
logos: string[],
updatedAt: string | null,
colorSlot: number,
primaryDomain: string | null,
lastDeployment: App.Data.Canvas.ProjectLastDeploymentData | null,
deploys: App.Data.Canvas.DeployDayData[],
canvas: App.Data.Canvas.CanvasThumbnailData | null,
iconUrl: string | null,
};
export type RenameProjectData = {
name: string,
};
export type ScalingPreviewData = {
servers: App.Data.Canvas.PlacementPreviewServerData[],
error: string | null,
};
export type ServicePanelData = {
serviceId: string,
tab: string,
settings: App.Data.Canvas.ServiceSettingsData | null,
canUpdate: boolean,
canDelete: boolean,
canConsole: boolean,
canData: boolean,
section: string | null,
};
export type ServicePlacementData = {
servers: App.Data.Canvas.PlacementServerOptionData[],
issue: App.Data.Canvas.PlacementIssueData | null,
environmentKind: string,
planError: string | null,
stateful: boolean,
pinnedServer: string | null,
memoryOvercommit: number,
cpuOvercommit: number | null,
};
export type ServiceSettingsData = {
source: App.Data.Canvas.ServiceSourceData,
detected: App.Data.Canvas.DetectedPlanData | null,
plan: App.Data.Canvas.PlanStatusData | null,
defaultRailpackVersion: string,
build: App.Data.Services.BuildSettings,
deploy: App.Data.Services.DeploySettings,
scaling: App.Data.Services.ScalingSettings,
enabled: boolean,
domains: App.Data.Canvas.DomainData[],
privateHost: string,
volumes: App.Data.Canvas.VolumeData[],
advanced: App.Data.Canvas.AdvancedServiceData,
appsDomain: string | null,
placement: App.Data.Canvas.ServicePlacementData | null,
edges: App.Data.Canvas.EdgeServerData[],
configFile: App.Data.ConfigAsCode.ManagedByFileData | null,
dockerfileGeneration: App.Data.Dockerfiles.DockerfileGenerationPanelData | null,
};
export type ServiceSourceData = {
type: string,
repository: string | null,
rootDirectory: string | null,
branch: string | null,
branchOverride: string | null,
environmentBranch: string | null,
image: string | null,
engine: App.Enums.DatabaseEngine | null,
version: string | null,
versions: string[],
parentName: string | null,
provider: string | null,
};
export type VolumeData = {
id: string,
mountPath: string,
sizeBytes: number | null,
server: string | null,
dockerName: string,
serverId: string | null,
serverDevice: App.Data.Servers.DeviceData | null,
serverStatus: App.Enums.ServerStatus | null,
};
export type WorkloadData = {
id: string,
server: string,
phase: App.Enums.WorkloadPhase,
containerId: string | null,
restartCount: number,
};
}
namespace CommandPalette {
export type PaletteData = {
projects: App.Data.CommandPalette.PaletteItemData[],
environments: App.Data.CommandPalette.PaletteItemData[],
services: App.Data.CommandPalette.PaletteItemData[],
deployments: App.Data.CommandPalette.PaletteItemData[],
servers: App.Data.CommandPalette.PaletteItemData[],
canDeploy: boolean,
canWriteVariables: boolean,
};
export type PaletteItemData = {
kind: string,
id: string,
label: string,
detail: string | null,
projectId: string | null,
environmentId: string | null,
status: string | null,
iconUrl: string | null,
};
}
namespace ConfigAsCode {
export type ConfigCheckData = {
valid: boolean,
errors: App.Data.ConfigAsCode.ConfigFileProblemData[],
warnings: App.Data.ConfigAsCode.ConfigFileProblemData[],
ignored: App.Data.ConfigAsCode.ConfigFileProblemData[],
changes: App.Data.Specs.ChangeItem[],
};
export type ConfigExcerptLineData = {
number: number,
text: string,
};
export type ConfigExportData = {
path: string,
content: string,
revision: number | null,
};
export type ConfigFileEnvironmentData = {
id: string,
name: string,
kind: App.Enums.EnvironmentKind,
status: App.Enums.ConfigAsCode.ConfigFileStatus | null,
commitSha: string | null,
checkedAt: string | null,
managedServices: number,
managedSettings: number,
errors: App.Data.ConfigAsCode.ConfigFileProblemData[],
warnings: App.Data.ConfigAsCode.ConfigFileProblemData[],
ignored: App.Data.ConfigAsCode.ConfigFileProblemData[],
fileUrl: string | null,
downloadUrl: string,
canSync: boolean,
};
export type ConfigFilePageData = {
settings: App.Data.ConfigAsCode.ConfigFileSettingsData,
repositories: App.Data.ConfigAsCode.ConfigRepositoryOptionData[],
repository: string | null,
environments: App.Data.ConfigAsCode.ConfigFileEnvironmentData[],
schemaUrl: string,
canUpdate: boolean,
};
export type ConfigFileProblemData = {
message: string,
line: number | null,
column: number | null,
path: string | null,
hint: string | null,
excerpt: App.Data.ConfigAsCode.ConfigExcerptLineData[],
};
export type ConfigFileSettingsData = {
enabled: boolean,
path: string,
repositoryId: number | null,
};
export type ConfigRepositoryOptionData = {
id: number,
fullName: string,
};
export type DeploymentConfigErrorsData = {
path: string,
commitSha: string | null,
repository: string | null,
errors: App.Data.ConfigAsCode.ConfigFileProblemData[],
};
export type ManagedByFileData = {
path: string,
repository: string | null,
commitSha: string | null,
status: App.Enums.ConfigAsCode.ConfigFileStatus,
editUrl: string | null,
fields: string[],
variables: string[],
domains: string[],
};
export type ManagedConfigData = {
services: Record<string, App.Data.ConfigAsCode.ManagedServiceData>,
};
export type ManagedServiceData = {
slug: string,
line: number,
fields: string[],
variables: string[],
domains: string[],
};
export type StagedConfigFileData = {
path: string,
commitSha: string | null,
status: App.Enums.ConfigAsCode.ConfigFileStatus,
waiting: boolean,
managedPaths: string[],
};
}
namespace Daemon {
export type ClientCertificateRequest = {
apiVersion: string,
kind: string,
publicKey: string,
};
}
namespace Deployments {
export type BuildHistoryEntryData = {
id: string,
status: App.Enums.DeploymentStatus,
buildDurationMs: number | null,
durationSeconds: number,
finishedAt: string,
};
export type DeploymentDetailData = {
deployment: App.Data.Deployments.DeploymentSummaryData,
projectName: string,
revision: number | null,
steps: App.Data.Deployments.DeploymentStepData[],
failure: App.Data.Deployments.DeploymentFailureData | null,
config: App.Data.Deployments.ResolvedConfigEntryData[],
env: App.Data.Deployments.EnvSnapshotEntryData[],
canRedeploy: boolean,
canCancel: boolean,
canPromote: boolean,
failureExplanation: App.Data.Deployments.FailureExplanationData | null,
buildHistory: App.Data.Deployments.BuildHistoryEntryData[],
logo: string,
builtOn: string | null,
serving: App.Data.Deployments.DeploymentRefData | null,
previous: App.Data.Deployments.DeploymentRefData | null,
compareUrl: string | null,
configErrors: App.Data.ConfigAsCode.DeploymentConfigErrorsData | null,
};
export type DeploymentFailureData = {
class: App.Enums.DeploymentFailureClass,
title: string,
message: string | null,
hint: string,
};
export type DeploymentRefData = {
id: string,
commitSha: string | null,
commitMessage: string | null,
status: App.Enums.DeploymentStatus,
queuedAt: string,
};
export type DeploymentStatsData = {
weekTotal: number,
weekFailed: number,
medianDurationSeconds: number | null,
services: App.Data.Deployments.ServiceDeploymentStatsData[],
};
export type DeploymentStepData = {
name: App.Enums.DeploymentStepName,
status: App.Enums.DeploymentStepStatus,
startedAt: string | null,
finishedAt: string | null,
message: string | null,
};
export type DeploymentSummaryData = {
id: string,
status: App.Enums.DeploymentStatus,
statusLabel: string,
trigger: App.Enums.DeploymentTrigger,
projectId: string,
serviceId: string,
serviceName: string,
serviceSlug: string,
environmentId: string,
environmentName: string,
gitRef: string | null,
commitSha: string | null,
commitMessage: string | null,
commitAuthor: string | null,
actorName: string | null,
failureClass: App.Enums.DeploymentFailureClass | null,
failureSummary: string | null,
isActive: boolean,
urls: string[],
queuedAt: string,
startedAt: string | null,
finishedAt: string | null,
durationSeconds: number | null,
rollbackOfId: string | null,
canRollback: boolean,
commitAuthorAvatarUrl: string | null,
};
export type EnvSnapshotEntryData = {
name: string,
hash: string,
};
export type FailureExplanationData = {
key: string,
title: string,
cause: string,
fix: string,
action: App.Enums.FailureFixAction | null,
params: Record<string, string | number>,
};
export type FailureFixData = {
action: App.Enums.FailureFixAction,
port: number | null,
seconds: number | null,
};
export type LogLineData = {
number: number,
time: string,
stream: string,
text: string,
section: string,
step: string | null,
};
export type LogPageData = {
lines: App.Data.Deployments.LogLineData[],
cursor: number,
hasMore: boolean,
};
export type ResolvedConfigEntryData = {
group: string,
key: string,
value: string,
};
export type ServiceDeploymentStatsData = {
serviceId: string,
environmentId: string,
medianDurationSeconds: number | null,
weekTotal: number,
weekFailed: number,
};
}
namespace Dockerfiles {
export type AiProviderSettingsData = {
enabled: boolean,
provider: string,
providerLabel: string,
model: string,
defaultModel: string,
suggestedModels: string[],
hasKey: boolean,
keyLast4: string | null,
keySetAt: string | null,
stub: boolean,
canManage: boolean,
};
export type DockerfileAttemptData = {
n: number,
outcome: string | null,
message: string | null,
lint: {
line: number,
message: string,
}[],
};
export type DockerfileFileSentData = {
path: string,
bytes: number,
truncated: boolean,
};
export type DockerfileGenerationData = {
id: string,
status: App.Enums.Dockerfiles.DockerfileGenerationStatus,
trigger: App.Enums.Dockerfiles.DockerfileGenerationTrigger,
model: string,
attempt: number,
maxAttempts: number,
commitSha: string | null,
branch: string | null,
dockerfile: string | null,
previousDockerfile: string | null,
port: number | null,
startCommand: string | null,
explanation: string | null,
confidence: App.Enums.Dockerfiles.DockerfileConfidence | null,
error: string | null,
attempts: App.Data.Dockerfiles.DockerfileAttemptData[],
filesSent: App.Data.Dockerfiles.DockerfileFileSentData[],
filesListed: number,
inputTokens: number,
outputTokens: number,
costUsd: number | null,
buildMs: number | null,
startedAfterMs: number | null,
createdAt: string,
finishedAt: string | null,
acceptedAt: string | null,
inUse: boolean,
pullRequestUrl: string | null,
canUse: boolean,
canCancel: boolean,
canOpenPullRequest: boolean,
pullRequestProvider: string | null,
repositoryPath: string,
};
export type DockerfileGenerationPanelData = {
available: boolean,
reason: string | null,
model: string,
providerLabel: string,
canStart: boolean,
canEnable: boolean,
planFailed: boolean,
latest: App.Data.Dockerfiles.DockerfileGenerationData | null,
};
export type StartDockerfileGenerationData = {
trigger: App.Enums.Dockerfiles.DockerfileGenerationTrigger,
};
export type TeamDockerfileGenerationData = {
enabled: boolean,
};
export type UpdateAiProviderData = {
enabled?: boolean,
model?: string,
apiKey?: string | null,
removeKey: boolean,
};
}
namespace Domains {
export type CertificateData = {
status: App.Enums.CertStatus,
issuer: string | null,
notBefore: string | null,
expiresAt: string | null,
wildcard: boolean,
error: string | null,
errorAt: string | null,
checkedAt: string | null,
warning: string | null,
};
export type DnsCheckData = {
status: string,
hostname: string,
addresses: string[],
expected: string[],
cname: string | null,
message: string,
checkedAt: string,
proxy: string | null,
reachedOverHttp: boolean,
};
}
namespace Environments {
export type CopyEnvironmentOptions = {
secrets: boolean,
variables: boolean,
serviceIds: string[] | null,
recordBase: boolean,
};
export type CreateEnvironmentData = {
name: string,
baseEnvironmentId: string | null,
triggers: App.Data.Environments.EnvironmentTriggers | null,
};
export type DeleteEnvironmentData = {
confirmName: string,
skipFinalBackup: boolean,
};
export type EnvironmentSettingsData = {
id: string,
name: string,
slug: string,
kind: App.Enums.EnvironmentKind,
triggers: App.Data.Environments.EnvironmentTriggers,
autoDeployPaused: boolean,
baseName: string | null,
services: number,
revision: number | null,
lastDeployedAt: string | null,
canUpdate: boolean,
canDelete: boolean,
servicesWithBackups: number,
backupInFlight: boolean,
pendingDeletion: App.Data.Backups.DeletionRequestData | null,
canSkipFinalBackup: boolean,
};
export type EnvironmentTriggers = {
branch: string | null,
tagPattern: string | null,
pullRequests: boolean,
};
export type UpdateEnvironmentData = {
name?: string,
triggers?: App.Data.Environments.EnvironmentTriggers,
};
}
namespace Git {
export type AddGitServerData = {
url: string,
allowPrivateNetwork: boolean,
};
export type CommitData = {
sha: string,
message: string,
authorName: string | null,
authorEmail: string | null,
authorLogin: string | null,
authorAvatarUrl: string | null,
};
export type ConnectGitProviderData = {
provider: string,
baseUrl: string,
method: string,
clientId: string | null,
clientSecret: string | null,
token: string | null,
allowPrivateNetwork: boolean,
};
export type DeploymentStatusReport = {
teamId: number,
installationId: number,
repositoryId: number,
deploymentKey: string,
serviceName: string,
environmentName: string,
productionEnvironment: boolean,
transientEnvironment: boolean,
commitSha: string,
state: App.Enums.Git.DeploymentReportState,
deploymentUrl: string,
environmentUrl: string | null,
description: string | null,
pullRequestNumber: number | null,
deploymentApi: boolean,
provider: string,
};
export type GitBranchData = {
name: string,
sha: string,
protected: boolean,
isDefault: boolean,
};
export type GitConnectionData = {
id: string,
provider: App.Enums.Git.GitProviderKind,
serverKind: string,
serverLabel: string,
name: string,
host: string,
url: string,
accountLogin: string | null,
authMethod: App.Enums.Git.GitAuthMethod,
status: App.Enums.Git.GitConnectionStatus,
statusLabel: string,
statusReason: string | null,
webhooksHealthy: boolean,
lastWebhookAt: string | null,
repositoriesCount: number,
hostKeys: App.Data.Git.HostKeyData[],
hostKeysConfirmed: boolean,
deployKey: string | null,
webhookUrl: string | null,
allowPrivateNetwork: boolean,
canManage: boolean,
};
export type GitHubAppData = {
id: string,
name: string,
slug: string,
ownerLogin: string | null,
htmlUrl: string,
installUrl: string,
webUrl: string,
isEnterprise: boolean,
createdAt: string | null,
webhook: App.Data.Git.WebhookHealthData,
installations: App.Data.Git.GitHubInstallationData[],
canManage: boolean,
};
export type GitHubInstallationData = {
id: string,
accountLogin: string,
accountType: string,
repositorySelection: App.Enums.Git.RepositorySelection,
repositoryCount: number,
suspended: boolean,
syncedAt: string | null,
settingsUrl: string,
};
export type GitRepositoryData = {
id: string,
installationId: string,
githubRepoId: number,
fullName: string,
defaultBranch: string | null,
private: boolean,
archived: boolean,
pushedAt: string | null,
provider: App.Enums.Git.GitProviderKind,
serverKind: string,
connectionName: string | null,
};
export type HostKeyData = {
type: string,
fingerprint: string,
};
export type ManifestRedirectData = {
action: string,
manifest: string,
};
export type RefHeadUpdate = {
previous: string | null,
changed: boolean,
};
export type StartManifestData = {
organization: string | null,
githubUrl: string | null,
};
export type TrackedRef = {
installationId: number,
repositoryId: number,
ref: string,
provider: App.Enums.Git.GitProviderKind,
};
export type WebhookHealthData = {
health: App.Enums.Git.WebhookHealth,
lastReceivedAt: string | null,
lastEvent: string | null,
lastStatus: App.Enums.Git.WebhookDeliveryStatus | null,
};
}
namespace Ingress {
export type DnsCredentialFieldData = {
key: string,
label: string,
secret: boolean,
pattern: string | null,
options: Record<string, string> | null,
};
export type DnsProviderCheckData = {
ok: boolean,
message: string,
zone: string | null,
checkedAt: string,
};
export type DnsProviderOptionData = {
value: App.Enums.DnsProvider,
label: string,
help: string,
fields: App.Data.Ingress.DnsCredentialFieldData[],
};
export type EdgeIngressStatusData = {
id: string,
name: string,
publicIpv4: string | null,
state: string | null,
routes: number | null,
storage: string | null,
mirrored: number | null,
message: string | null,
reportedAt: string | null,
controlPlane: boolean,
shared: boolean,
publicIpv6: string | null,
};
export type EdgeOverviewData = {
edges: number,
singlePointOfFailure: boolean,
servers: number,
singleServer: boolean,
degradedStorage: string[],
guideUrl: string,
};
export type IngressSettingsData = {
appsDomain: string | null,
dnsProvider: App.Enums.DnsProvider | null,
providers: App.Data.Ingress.DnsProviderOptionData[],
hasToken: boolean,
storedFields: Record<string, string | null>,
verification: App.Data.Ingress.DnsProviderCheckData | null,
modulesAvailable: boolean,
wildcardActive: boolean,
storageConfigured: boolean,
storageAddress: string | null,
edges: App.Data.Ingress.EdgeIngressStatusData[],
overview: App.Data.Ingress.EdgeOverviewData,
};
}
namespace Instance {
export type CreateOwnerData = {
token: string,
name: string,
email: string,
password: string,
};
export type DashboardCertificateData = {
state: string,
issuer: string | null,
notAfter: string | null,
lastError: string | null,
};
export type DashboardDomainChangeData = {
hostname: string,
url: string,
status: string,
steps: App.Data.Instance.DashboardDomainStepData[],
dns: App.Data.Domains.DnsCheckData | null,
probe: App.Data.Instance.DashboardProbeData | null,
certificate: App.Data.Instance.DashboardCertificateData | null,
impact: App.Data.Instance.DashboardSwitchImpactData,
checkedAt: string | null,
readyAt: string | null,
createdAt: string | null,
};
export type DashboardDomainSettingsData = {
current: App.Data.Instance.DashboardOriginData,
routable: boolean,
records: App.Data.Canvas.DnsRecordData[],
change: App.Data.Instance.DashboardDomainChangeData | null,
activeHostname: string | null,
switchBackTo: string | null,
activeSince: string | null,
legacy: App.Data.Instance.DashboardLegacyOriginData[],
servers: App.Data.Instance.DashboardServerUrlData[],
meshLoginUrl: string | null,
githubApps: App.Data.Instance.DashboardGitHubAppData[],
githubUrls: {
webhook: string,
callback: string,
setup: string,
homepage: string,
},
transitionDays: number,
resetCommand: string,
};
export type DashboardDomainStepData = {
key: string,
state: string,
detail: string | null,
};
export type DashboardGitHubAppData = {
name: string,
team: string,
appUrl: string,
hasWebhook: boolean,
};
export type DashboardLegacyOriginData = {
url: string,
host: string,
since: string,
until: string,
inTransition: boolean,
isInstallUrl: boolean,
};
export type DashboardOriginData = {
url: string,
host: string,
https: boolean,
ip: boolean,
switched: boolean,
installUrl: string,
switchedAt: string | null,
};
export type DashboardProbeData = {
ok: boolean,
message: string,
via: string | null,
checkedAt: string,
};
export type DashboardServerUrlData = {
id: string,
name: string,
state: string,
url: string | null,
message: string | null,
current: boolean,
};
export type DashboardSwitchImpactData = {
rpId: string | null,
keepsPasskeys: boolean,
passkeysAffected: number,
yourPasskeysAffected: number,
};
export type OnboardingData = {
steps: App.Data.Instance.OnboardingStepData[],
progress: App.Data.Instance.OnboardingProgressData,
server: App.Data.Servers.ServerData | null,
serverAddress: string | null,
githubConnected: boolean,
githubAppName: string | null,
appsDomain: string | null,
edgeAddresses: string[],
sslipExample: string | null,
dashboardHost: string,
teamBackupsConfigured: boolean,
recoveryKit: App.Data.Backups.RecoveryKitStatusData,
projectCount: number,
canCreateProject: boolean,
githubInstallUrl: string | null,
githubAccount: string | null,
githubRepositories: number,
canConnectGitHub: boolean,
recommendTwoFactor: boolean,
appsDomainVerified: boolean | null,
smallServerHint: App.Data.Servers.SmallServerHintData | null,
};
export type OnboardingProgressData = {
done: number,
total: number,
complete: boolean,
dismissed: boolean,
};
export type OnboardingStepData = {
id: string,
status: string,
skippable: boolean,
};
export type RealtimeConfigData = {
key: string,
host: string | null,
port: number | null,
scheme: string | null,
};
export type StartDashboardDomainData = {
hostname: string,
};
}
namespace Logs {
export type EnvironmentLogRetentionData = {
id: string,
name: string,
kind: App.Enums.EnvironmentKind,
enabled: boolean,
days: number,
maxMb: number,
storedBytes: number,
oldestAt: string | null,
canUpdate: boolean,
};
export type LogBucketData = {
start: number,
end: number,
total: number,
errors: number,
};
export type LogEntryData = {
id: string,
time: string,
level: string,
source: string,
service: string,
serviceId: string | null,
replica: string | null,
server: string | null,
deployment: string | null,
deploymentId: string | null,
stream: string,
message: string,
environment: string | null,
project: string | null,
};
export type LogExplorerPageData = {
entries: App.Data.Logs.LogEntryData[],
markers: App.Data.Logs.LogMarkerData[],
from: string,
to: string,
cursor: number,
truncated: boolean,
tailing: number,
buckets: App.Data.Logs.LogBucketData[],
matches: number,
};
export type LogMarkerData = {
deploymentId: string,
at: string,
label: string,
status: string,
};
export type LogQueryData = {
range: string,
source: string,
service: string | null,
after: number,
q: string | null,
};
export type LogTailData = {
service: string | null,
};
export type RuntimeLogRetentionData = {
enabled: boolean,
days: number,
maxMb: number,
};
}
namespace Metrics {
export type DeployMarkerData = {
deploymentId: string,
serviceId: string,
at: string,
status: string,
serviceName: string | null,
sha: string | null,
};
export type EnvironmentTotalsData = {
requests: App.Data.Metrics.RequestSeriesData | null,
cpuCores: (number| null)[],
memMb: (number| null)[],
cpuAvg: number | null,
memAvg: number | null,
previousCpuAvg: number | null,
previousMemAvg: number | null,
};
export type FleetCapacityData = {
servers: number,
cpuCores: number,
cpuUsedCores: number,
cpuPct: number | null,
memUsedMb: number,
memTotalMb: number,
memPct: number | null,
diskUsedMb: number,
diskTotalMb: number,
diskPct: number | null,
};
export type FleetMetricsData = {
range: App.Enums.MetricRange,
bucketSeconds: number,
timestamps: string[],
servers: App.Data.Metrics.ServerUsageData[],
totals: App.Data.Metrics.FleetTotalsData,
};
export type FleetTotalsData = {
cpuPct: (number| null)[],
memPct: (number| null)[],
diskPct: (number| null)[],
current: App.Data.Metrics.FleetCapacityData | null,
};
export type HostSeriesData = {
cpuPct: (number| null)[],
memUsedMb: (number| null)[],
memPct: (number| null)[],
diskUsedMb: (number| null)[],
diskPct: (number| null)[],
netRxBps: (number| null)[],
netTxBps: (number| null)[],
load1: (number| null)[],
};
export type HostUsageNowData = {
at: string,
cpuPct: number | null,
memUsedMb: number | null,
memTotalMb: number | null,
memPct: number | null,
diskUsedMb: number | null,
diskTotalMb: number | null,
diskPct: number | null,
netRxBps: number | null,
netTxBps: number | null,
load1: number | null,
};
export type MetricsQueryData = {
range: App.Enums.MetricRange,
environment: string | null,
service: string | null,
};
export type MetricsServiceData = {
id: string,
name: string,
slug: string,
logo: string,
};
export type ProjectMetricsData = {
projectId: string,
environmentId: string,
range: App.Enums.MetricRange,
bucketSeconds: number,
timestamps: string[],
services: App.Data.Metrics.ServiceUsageSeriesData[],
deploys: App.Data.Metrics.DeployMarkerData[],
totals: App.Data.Metrics.EnvironmentTotalsData | null,
};
export type ProjectUsageData = {
projectId: string | null,
name: string,
colorSlot: number | null,
memMb: number,
cpuCores: number,
memShare: number | null,
};
export type ReplicaUsageSeriesData = {
replica: number,
cpuCores: (number| null)[],
memMb: (number| null)[],
};
export type RequestSeriesData = {
requestsPerSecond: (number| null)[],
errorRatePct: (number| null)[],
p50Ms: (number| null)[],
p95Ms: (number| null)[],
bytesOutPerSecond: (number| null)[],
totals: App.Data.Metrics.RequestTotalsData,
previous: App.Data.Metrics.RequestTotalsData | null,
};
export type RequestTotalsData = {
requests: number,
status2xx: number,
status3xx: number,
status4xx: number,
status5xx: number,
bytesIn: number,
bytesOut: number,
requestsPerSecond: number,
errorRatePct: number | null,
p50Ms: number | null,
p95Ms: number | null,
};
export type ServerMetricsData = {
serverId: string,
range: App.Enums.MetricRange,
bucketSeconds: number,
timestamps: string[],
host: App.Data.Metrics.HostSeriesData,
current: App.Data.Metrics.HostUsageNowData | null,
usedBy: App.Data.Metrics.ProjectUsageData[],
services: App.Data.Metrics.ServiceUsageSeriesData[],
deploys: App.Data.Metrics.DeployMarkerData[],
traffic: App.Data.Metrics.RequestSeriesData | null,
};
export type ServerUsageData = {
serverId: string,
cpuPct: (number| null)[],
memPct: (number| null)[],
current: App.Data.Metrics.HostUsageNowData | null,
usedBy: App.Data.Metrics.ProjectUsageData[],
};
export type ServiceUsageSeriesData = {
serviceId: string,
serviceName: string,
environmentId: string,
environmentName: string,
projectId: string,
projectName: string,
colorSlot: number,
cpuCores: (number| null)[],
memMb: (number| null)[],
currentCpuCores: number | null,
currentMemMb: number | null,
replicas: App.Data.Metrics.ReplicaUsageSeriesData[],
requests: App.Data.Metrics.RequestSeriesData | null,
};
}
namespace Moves {
export type ControlPlaneMoveData = {
id: string,
status: App.Enums.Moves.ControlPlaneMoveStatus,
code: string | null,
receiverHostname: string | null,
receiverArch: string | null,
receiverAddress: string | null,
receiverSeenAt: string | null,
receiverWaiting: boolean,
source: App.Data.Moves.MoveServerData | null,
target: App.Data.Moves.MoveServerData | null,
error: string | null,
tokenExpiresAt: string | null,
frozenAt: string | null,
handedOverAt: string | null,
newUrl: string | null,
dumpSize: string | null,
};
export type MoveDnsRecordData = {
kind: string,
host: string,
addresses: string[],
remove: string[],
check: App.Data.Domains.DnsCheckData | null,
};
export type MovePlanData = {
source: App.Data.Moves.MoveServerData,
target: App.Data.Moves.MoveServerData | null,
items: App.Data.Moves.MovePlanItemData[],
blockers: string[],
warnings: string[],
dns: App.Data.Moves.MoveDnsRecordData[],
platformFrom: string | null,
platformTo: string | null,
addsEdge: boolean,
downtimeSeconds: number | null,
copyBytes: number | null,
canBackUpFirst: boolean,
};
export type MovePlanItemData = {
instanceId: string,
service: string,
name: string,
kind: App.Enums.Moves.MoveItemKind,
engine: string | null,
expectedBytes: number | null,
downtimeSeconds: number | null,
rebuild: boolean,
stops: string[],
note: string | null,
};
export type MovePreflightCheckData = {
key: string,
label: string,
status: string,
message: string,
};
export type MoveServerData = {
id: string,
name: string,
status: App.Enums.ServerStatus,
roles: App.Enums.ServerRole[],
isControlPlane: boolean,
platform: string | null,
addresses: string[],
diskFreeMb: number | null,
device: App.Enums.DeviceKind,
};
export type ServerMoveData = {
id: string,
status: App.Enums.Moves.ServerMoveStatus,
source: App.Data.Moves.MoveServerData,
target: App.Data.Moves.MoveServerData,
controlPlane: boolean,
edgeAdded: boolean,
backupFirst: boolean,
items: App.Data.Moves.ServerMoveItemData[],
done: number,
failed: number,
error: string | null,
createdAt: string | null,
readyAt: string | null,
finishedAt: string | null,
};
export type ServerMoveItemData = {
id: string,
instanceId: string,
name: string,
kind: App.Enums.Moves.MoveItemKind,
status: App.Enums.Moves.MoveItemStatus,
failedStep: string | null,
message: string | null,
expectedBytes: number | null,
copiedBytes: number | null,
attempts: number,
stoppedAt: string | null,
startedAt: string | null,
finishedAt: string | null,
canRetry: boolean,
};
}
namespace Previews {
export type ApprovePreviewInput = {
headSha: string,
};
export type CloneCandidateData = {
id: string,
service: string,
environment: string,
production: boolean,
sizeBytes: number | null,
backedUpAt: string | null,
allowed: boolean,
reason: string | null,
};
export type PreviewCanvasData = {
id: string,
number: number,
title: string,
headRef: string,
baseName: string,
status: App.Enums.Previews.PreviewStatus,
statusLabel: string,
health: string,
statusReason: string | null,
secretsWithheld: boolean,
expiresAt: string | null,
edgeBadges: App.Data.Previews.PreviewEdgeBadgeData[],
};
export type PreviewData = {
id: string,
number: number,
title: string,
author: string,
repository: string,
pullRequestUrl: string | null,
headRef: string,
baseRef: string,
headSha: string,
headCommit: string,
isFork: boolean,
status: App.Enums.Previews.PreviewStatus,
statusLabel: string,
statusReason: string | null,
environmentId: string | null,
health: string,
urls: App.Data.Previews.PreviewUrlData[],
databases: App.Data.Previews.PreviewDatabaseData[],
secretsWithheld: boolean,
approvedBy: string | null,
expiresAt: string | null,
lastActivityAt: string,
shareLinks: App.Data.Previews.ShareLinkData[],
protected: boolean,
};
export type PreviewDatabaseData = {
service: string,
strategy: App.Enums.PreviewStrategy,
state: string | null,
message: string | null,
source: string | null,
downgradedFrom: App.Enums.PreviewStrategy | null,
downgradeReason: string | null,
};
export type PreviewEdgeBadgeData = {
serviceId: string,
serviceName: string,
label: string,
tone: string,
};
export type PreviewLinkData = {
environmentId: string,
number: number,
title: string,
status: App.Enums.Previews.PreviewStatus,
};
export type PreviewServiceData = {
serviceId: string,
name: string,
engine: string,
strategy: App.Enums.PreviewStrategy,
cloneSourceId: string | null,
sanitizeCommand: string[] | null,
candidates: App.Data.Previews.CloneCandidateData[],
};
export type PreviewSettingsData = {
enabled: boolean,
baseEnvironmentId: string | null,
effectiveBaseId: string | null,
targetBranches: string | null,
requiredLabel: string | null,
maxConcurrent: number,
expiryDays: number,
forkPolicy: App.Enums.Previews.ForkPolicy,
protection: boolean,
allowProductionClone: boolean,
hasBypassSecret: boolean,
bypassHeader: string,
cloneLimitMb: number,
baseOptions: App.Data.Canvas.EnvironmentData[],
services: App.Data.Previews.PreviewServiceData[],
canManage: boolean,
canAllowProductionClone: boolean,
};
export type PreviewSettingsInput = {
enabled: boolean,
baseEnvironmentId: string | null,
targetBranches: string | null,
requiredLabel: string | null,
maxConcurrent: number,
expiryDays: number,
forkPolicy: App.Enums.Previews.ForkPolicy,
protection: boolean,
allowProductionClone: boolean,
};
export type PreviewStrategyInput = {
strategy: App.Enums.PreviewStrategy,
cloneSourceId: string | null,
sanitizeCommand: string[] | null,
};
export type PreviewUrlData = {
service: string,
url: string,
};
export type PullRequestData = {
provider: App.Enums.Previews.PreviewProvider,
installationId: number | null,
repositoryId: number,
repositoryName: string,
action: string,
number: number,
title: string,
headSha: string,
headRef: string,
baseRef: string,
isFork: boolean,
authorLogin: string,
authorPermission: string | null,
merged: boolean,
labels: string[],
changedFiles: string[] | null,
label: string | null,
senderLogin: string | null,
senderPermission: string | null,
};
export type ShareLinkData = {
id: string,
label: string | null,
expiresAt: string,
usable: boolean,
lastUsedAt: string | null,
createdBy: string | null,
url: string | null,
};
export type ShareLinkInput = {
label: string | null,
days: number,
};
}
namespace Projects {
export type CreateProjectData = {
name: string,
};
export type DeleteProjectData = {
confirmName: string,
skipFinalBackup: boolean,
};
export type ImportAppData = {
name: string,
rootDirectory: string | null,
port: number | null,
framework: string | null,
processes: App.Data.Projects.ImportProcessData[],
};
export type ImportDatabaseData = {
engine: App.Enums.DatabaseEngine,
name: string,
connectTo: string[],
};
export type ImportProcessData = {
name: string,
command: string[],
};
export type ImportRepositoryData = {
name: string,
repository: string,
branch: string,
apps: App.Data.Projects.ImportAppData[],
databases: App.Data.Projects.ImportDatabaseData[],
};
export type InspectedAppData = {
rootDirectory: string,
name: string,
runtime: string,
selected: boolean,
envExample: string | null,
envKeys: string[],
};
export type PlanSuggestionsData = {
processes: App.Data.Projects.SuggestedProcessData[],
databases: App.Data.Projects.SuggestedDatabaseData[],
};
export type ProjectIconData = {
mode: App.Enums.ProjectIconMode,
url: string | null,
source: string | null,
checkedAt: string | null,
};
export type ProjectSettingsData = {
productionSecretsMustDiffer: boolean,
configFile: App.Data.ConfigAsCode.ConfigFileSettingsData,
};
export type RepositoryInspectionData = {
repository: string,
branch: string,
apps: App.Data.Projects.InspectedAppData[],
databases: App.Data.Projects.SuggestedDatabaseData[],
complete: boolean,
};
export type ResetProjectIconData = {
mode: App.Enums.ProjectIconMode,
};
export type SuggestedDatabaseData = {
engine: App.Enums.DatabaseEngine,
reason: string,
apps: string[],
};
export type SuggestedProcessData = {
name: string,
command: string[],
};
}
namespace Promotions {
export type PromoteChangesData = {
from: string,
to: string,
items: App.Data.Promotions.PromoteItemInput[],
images: string[],
};
export type PromoteItemInput = {
path: string,
value: string | null,
copyValue: boolean,
};
export type PromotionComparisonData = {
from: App.Data.Promotions.PromotionEnvironmentData,
to: App.Data.Promotions.PromotionEnvironmentData,
environments: App.Data.Promotions.PromotionEnvironmentData[],
baseDescription: string,
hasBase: boolean,
items: App.Data.Promotions.PromotionItem[],
services: App.Data.Promotions.PromotionServiceData[],
images: App.Data.Promotions.PromotionImageData[],
offered: number,
secretsMustDiffer: boolean,
canPromote: boolean,
targetHasStagedChanges: boolean,
};
export type PromotionEntry = {
type: App.Enums.PromotionEntryType,
fingerprint: string,
label: string,
promotion: App.Enums.PromotionClass,
effect: App.Enums.ChangeEffect,
serviceId: string | null,
key: string | null,
display: string | null,
secret: boolean,
generated: boolean,
generator: string | null,
valueFingerprint: string | null,
holdsData: boolean,
dependsOn: string[],
};
export type PromotionEnvironmentCardData = {
id: string,
name: string,
kind: App.Enums.EnvironmentKind,
revision: number | null,
deployedAt: string | null,
services: number,
live: number,
};
export type PromotionEnvironmentData = {
id: string,
name: string,
kind: App.Enums.EnvironmentKind,
};
export type PromotionImageData = {
serviceId: string,
name: string,
deploymentId: string,
commitSha: string | null,
sameInputs: boolean,
differences: string[],
};
export type PromotionItem = {
path: string,
type: App.Enums.PromotionEntryType,
kind: App.Enums.ChangeKind,
promotion: App.Enums.PromotionClass,
effect: App.Enums.ChangeEffect,
label: string,
serviceId: string | null,
key: string | null,
before: string | null,
after: string | null,
base: string | null,
conflict: boolean,
selectable: boolean,
selected: boolean,
secret: boolean,
needsValue: boolean,
acceptsValue: boolean,
canCopyValue: boolean,
copyBlockedReason: string | null,
warning: string | null,
note: string | null,
dependsOn: string[],
};
export type PromotionServiceData = {
id: string,
name: string,
slug: string,
kind: App.Enums.ServiceKind,
};
export type PromotionSnapshot = {
entries: Record<string, App.Data.Promotions.PromotionEntry>,
};
}
namespace Protocol {
namespace V1 {
export type AmbassadorSpec = {
network: string,
alias: string,
port: number,
upstreams: string[],
};
export type AppliedPreset = {
name: string,
version: number,
decisions: App.Data.Protocol.V1.PresetDecision[],
};
export type BackupResult = {
snapshotId: string,
sizeBytes: number,
durationMs: number,
addedBytes?: number,
};
export type BackupSnapshotsResult = {
snapshots: App.Data.Protocol.V1.SnapshotInfo[],
truncated?: boolean,
};
export type BroadcastAuth = {
auth: string,
};
export type BroadcastAuthRequest = {
apiVersion: string,
kind: string,
socketId: string,
channel: string,
};
export type BuildResult = {
image: string,
durationMs: number,
summary: App.Data.Protocol.V1.BuildSummary | null,
secretsHash: string | null,
attestations: string[] | null,
};
export type BuildSummary = {
builder: string,
builderReason: string,
dockerfilePath: string | null,
railpackVersion: string | null,
provider: string | null,
framework: string | null,
packageManager: string | null,
packages: App.Data.Protocol.V1.ResolvedPackage[] | null,
installCommand: string | null,
buildCommand: string | null,
startCommand: string | null,
port: number | null,
presets: App.Data.Protocol.V1.AppliedPreset[] | null,
runtimeEnv: Record<string, string> | null,
processes: App.Data.Protocol.V1.ProcessSuggestion[] | null,
releaseCommand: string[] | null,
healthPath: string | null,
buildEnv: string[] | null,
envReferences: string[] | null,
warnings: App.Data.Protocol.V1.BuildWarning[] | null,
plan: Record<string, any> | null,
next: App.Data.Protocol.V1.NextBuildInfo | null,
};
export type BuildWarning = {
code: string,
message: string,
};
export type CertStorageStatus = {
state: string,
mirrored: number,
message?: string,
};
export type CertificateStatus = {
host: string,
state: string,
wildcard?: boolean,
issuer?: string,
notBefore?: string | null,
notAfter?: string | null,
lastError?: string,
lastErrorAt?: string | null,
};
export type ClientCertificate = {
serverId: string,
certificate: string,
caCertificates: string[],
notBefore: string,
notAfter: string,
renewAfter: string,
};
export type DaemonConfig = {
nudge: App.Data.Protocol.V1.NudgeConfig | null,
};
export type DataExportResult = {
format: string,
sizeBytes: number,
chunks: number,
sha256: string,
durationMs: number,
compressed?: boolean,
};
export type DataImportResult = {
mode: unknown,
empty: boolean,
durationMs: number,
tables?: number,
files?: number,
sizeBytes?: number,
serverVersion?: string,
rootOwner?: {
uid: number,
gid: number,
} | null,
format?: string,
compressed?: boolean,
sourceVersion?: string,
readBytes?: number,
warnings?: string[],
};
export type EnrollRequest = {
apiVersion: string,
kind: string,
token: string,
signingKey: string,
sealingKey: string,
daemonVersion: string,
protocolVersions: string[],
host: App.Data.Protocol.V1.HostFacts,
};
export type EnrollResponse = {
serverId: string,
status: unknown,
controlPlaneKeys: Record<string, string>,
registry: App.Data.Protocol.V1.RegistryInfo,
pollIntervals: App.Data.Protocol.V1.PollIntervals,
nudge: App.Data.Protocol.V1.NudgeConfig | null,
};
export type HardwareFacts = {
cpuModel?: string,
cpuVendor?: string,
dmi?: Record<string, any> | null,
deviceTreeModel?: string,
virtualization?: string,
osName?: string,
kernel?: string,
timezone?: string,
battery?: boolean,
hostModel?: string,
};
export type HealthSpec = {
type: string,
startPeriod: string,
interval: string,
timeout: string,
failureThreshold: number,
path: string | null,
expectStatus: string | null,
};
export type HostFacts = {
hostname: string,
os: string,
arch: string,
cpus: number,
memoryMb?: number,
hardware?: App.Data.Protocol.V1.HardwareFacts | null,
};
export type HostStatus = {
cpus: number,
memoryMb: number,
diskTotalMb: number,
diskFreeMb: number,
daemonVersion: string,
dockerVersion?: string,
health?: Record<string, any> | null,
mesh?: Record<string, any> | null,
hardware?: App.Data.Protocol.V1.HardwareFacts | null,
links?: Record<string, any>[] | null,
features?: string[] | null,
controlPlane?: Record<string, any> | null,
runtime?: Record<string, any> | null,
publicAddresses?: Record<string, any> | null,
};
export type IngressSpec = {
storage: {
address: string,
keyPrefix: string,
sealed: string,
fingerprint: string,
} | null,
dns: {
provider: string,
domains: string[],
sealed: string,
fingerprint: string,
} | null,
};
export type IngressStatus = {
state: string,
routes: number,
appliedAt?: string | null,
message?: string,
certificates?: App.Data.Protocol.V1.CertificateStatus[] | null,
storage?: App.Data.Protocol.V1.CertStorageStatus | null,
};
export type InspectedFile = {
path: string,
content: string,
truncated: boolean,
};
export type LeaseRequest = {
apiVersion: string,
kind: string,
max: number,
types: string[],
};
export type LogBatch = {
apiVersion: string,
kind: string,
chunks: App.Data.Protocol.V1.LogChunk[],
};
export type LogChunk = {
source: App.Data.Protocol.V1.LogSource,
seq: number,
lines: App.Data.Protocol.V1.LogLine[],
};
export type LogLine = {
time: string,
stream: string,
text: string,
step?: string,
};
export type LogSource = {
kind: App.Enums.LogSourceKind,
id: string,
};
export type MaintenanceResult = {
durationMs: number,
removedSnapshots?: number,
};
export type MeshAllowRule = {
port: number,
from: string[],
};
export type NetworkSpec = {
id: string,
name: string,
subnet: string,
};
export type NextBuildInfo = {
adapterVersion: string,
nextVersion: string,
middleware: boolean,
imageOptimization: boolean,
sharp: boolean,
cacheHandler: boolean,
routes: App.Data.Protocol.V1.NextRouteCounts,
deploymentId: string | null,
serverEntry: string | null,
middlewareRuntime: string | null,
isrRoutes: string[] | null,
};
export type NextRouteCounts = {
appPages: number,
appRoutes: number,
pages: number,
pagesApi: number,
staticFiles: number,
prerendered: number,
isr: number,
edge: number,
};
export type NodeState = {
serverId: string,
version: number,
issuedAt: string,
system: App.Data.Protocol.V1.SystemSpec,
networks: App.Data.Protocol.V1.NetworkSpec[],
volumes: App.Data.Protocol.V1.VolumeSpec[],
workloads: App.Data.Protocol.V1.WorkloadSpec[],
ambassadors: App.Data.Protocol.V1.AmbassadorSpec[],
routes: App.Data.Protocol.V1.RouteSpec[],
meshAllow: App.Data.Protocol.V1.MeshAllowRule[],
deleteVolumes: App.Data.Protocol.V1.VolumeDeletion[],
ingress: App.Data.Protocol.V1.IngressSpec | null,
};
export type NudgeConfig = {
key: string,
url: string | null,
};
export type PlanResult = {
durationMs: number,
summary: App.Data.Protocol.V1.BuildSummary,
};
export type PollIntervals = {
state: string,
tasks: string,
};
export type PresetDecision = {
id: string,
title: string,
detail: string | null,
};
export type ProbeOutcome = {
listening: boolean,
afterMs: number,
exited: boolean,
httpStatus?: number,
listeningPorts?: number[],
};
export type ProbeStatus = {
state: string,
consecutiveFailures: number,
lastError?: string,
};
export type ProcessSuggestion = {
name: string,
args: string[],
};
export type PullProgress = {
stage: string,
downloadedBytes: number,
totalBytes: number,
};
export type RegistryInfo = {
address: string,
forwardTo: string | null,
};
export type RepoInspection = {
tree: string[] | null,
treeTruncated: boolean,
files: App.Data.Protocol.V1.InspectedFile[] | null,
};
export type Report = {
apiVersion: string,
kind: string,
serverId: string,
observedAt: string,
seq: number,
full: boolean,
appliedVersion: number,
workloads: App.Data.Protocol.V1.WorkloadStatus[],
host?: App.Data.Protocol.V1.HostStatus | null,
stateRejected?: App.Data.Protocol.V1.StateRejection | null,
volumeDeletions?: App.Data.Protocol.V1.VolumeDeletionResult[] | null,
ingress?: App.Data.Protocol.V1.IngressStatus | null,
metrics?: Record<string, any> | null,
walArchives?: Record<string, any>[] | null,
};
export type ResolvedPackage = {
name: string,
version: string,
source: string | null,
};
export type RestoreResult = {
sizeBytes: number,
durationMs: number,
restoredTo?: string | null,
};
export type RouteSpec = {
id: string,
hosts: string[],
tls: string,
upstreams: ({
workload: string,
port: number,
}| {
address: string,
})[],
redirectWwwTo: string | null,
headers: Record<string, string> | null,
protection: {
forwardAuth: {
url: string,
stripHeaders?: string[],
},
} | null,
loadBalancing: string | null,
clientAuth: {
mode: string,
caCertificates: string[],
edgeSecret: {
sealed: string,
fingerprint: string,
},
} | null,
};
export type RunOnceResult = {
exitCode: number,
durationMs: number,
probe?: App.Data.Protocol.V1.ProbeOutcome | null,
};
export type SelfUpdateResult = {
scope: unknown,
fromVersion: string,
toVersion: string,
rolledBack: boolean,
};
export type SessionCommandResult = {
op: string,
status: unknown,
rows: number,
durationMs: number,
truncated?: boolean,
};
export type SessionResult = {
endReason: string,
bytesIn: number,
bytesOut: number,
startedAt?: string,
exitCode?: number | null,
commands?: App.Data.Protocol.V1.SessionCommandResult[],
};
export type SnapshotInfo = {
id: string,
time: string,
tags: string[],
sizeBytes?: number,
};
export type StateRejection = {
version: number,
error: string,
rejectedAt: string,
};
export type SystemContainer = {
enabled: boolean,
image: string | null,
};
export type SystemSpec = {
caddy: App.Data.Protocol.V1.SystemContainer,
buildkit: App.Data.Protocol.V1.SystemContainer,
tailscale: App.Data.Protocol.V1.TailscaleSpec,
registryForwardTo: string | null,
registryServeOnMesh: boolean,
certStorageServeOnMesh: boolean,
certStorageAllowFrom: string[],
linkProbePeers: {
serverId: string,
ipv4: string,
signingKey: string,
}[] | null,
controlPlaneUrl: string | null,
};
export type TailscaleSpec = {
enabled: boolean,
image: string | null,
loginServer: string | null,
tags: string[],
authKeySealed: string | null,
authKeyFingerprint: string | null,
};
export type Task = {
id: string,
type: App.Enums.DaemonTaskType,
leaseExpiresAt: string,
attempt: number,
deadline: string,
spec: object,
};
export type TaskCompletion = {
apiVersion: string,
kind: string,
attempt: number,
outcome: unknown,
error?: string,
build?: App.Data.Protocol.V1.BuildResult | null,
runOnce?: App.Data.Protocol.V1.RunOnceResult | null,
plan?: App.Data.Protocol.V1.PlanResult | null,
failure?: App.Data.Protocol.V1.TaskFailure | null,
backup?: App.Data.Protocol.V1.BackupResult | null,
restore?: App.Data.Protocol.V1.RestoreResult | null,
snapshots?: App.Data.Protocol.V1.BackupSnapshotsResult | null,
maintenance?: App.Data.Protocol.V1.MaintenanceResult | null,
selfUpdate?: App.Data.Protocol.V1.SelfUpdateResult | null,
verify?: App.Data.Protocol.V1.VerifyResult | null,
session?: App.Data.Protocol.V1.SessionResult | null,
inspection?: App.Data.Protocol.V1.RepoInspection | null,
dataImport?: App.Data.Protocol.V1.DataImportResult | null,
dataExport?: App.Data.Protocol.V1.DataExportResult | null,
};
export type TaskFailure = {
class: string,
retryable: boolean,
step: string | null,
hints: App.Data.Protocol.V1.BuildWarning[] | null,
summary: App.Data.Protocol.V1.BuildSummary | null,
};
export type TaskHeartbeat = {
apiVersion: string,
kind: string,
attempt: number,
progress?: string,
};
export type TaskHeartbeatResult = {
leaseExpiresAt: string,
cancel: boolean,
};
export type TaskLease = {
tasks: App.Data.Protocol.V1.Task[],
};
export type VerifyResult = {
passed: boolean,
checks: {
name: string,
passed: boolean,
detail?: string,
}[],
durationMs: number,
tables?: number,
rows?: number,
files?: number,
bytes?: number,
restoredTo?: string | null,
};
export type VolumeDeletion = {
id: string,
name: string,
service: string,
environment: string,
requestedAt: string,
};
export type VolumeDeletionResult = {
id: string,
name: string,
outcome: string,
at: string,
message?: string,
};
export type VolumeSpec = {
id: string,
name: string,
};
export type WorkloadSpec = {
id: string,
service: string,
environment: string,
deployment: string,
image: string,
sealedEnv: string,
envFingerprint: string,
cpus: number,
memoryMb: number,
pids: number,
networks: {
network: string,
aliases: string[],
}[],
role: string,
command: string[] | null,
args: string[] | null,
port: number | null,
health: App.Data.Protocol.V1.HealthSpec | null,
capabilities: string[] | null,
mounts: {
volume: string,
target: string,
}[] | null,
restart: string,
drainUntil: string | null,
publishOnMesh: number | null,
shipLogs: boolean,
walArchive: Record<string, any> | null,
walArchiveFingerprint: string | null,
stop: {
signal: string,
timeout: string,
noInit?: boolean,
} | null,
schedule: {
cron: string,
timeZone: string,
} | null,
};
export type WorkloadStatus = {
id: string,
phase: string,
observedAt: string,
restartCount: number,
containerId?: string,
startedAt?: string | null,
exitCode?: number | null,
message?: string,
detectedPorts?: number[] | null,
health?: App.Data.Protocol.V1.ProbeStatus | null,
pull?: App.Data.Protocol.V1.PullProgress | null,
};
}
}
namespace Registry {
export type RegistryScope = {
repository: string,
actions: string[],
};
export type RegistrySettingsData = {
storage: App.Data.Registry.RegistryStorageData | null,
applied: boolean | null,
writtenAt: string | null,
applyCommand: string,
};
export type RegistryStorageData = {
endpoint: string,
bucket: string,
region: string | null,
prefix: string,
forcePathStyle: boolean,
allowPrivateNetwork: boolean,
updatedAt: string | null,
};
export type RegistryStorageInput = {
endpoint: string,
bucket: string,
region: string | null,
prefix: string | null,
accessKeyId: string | null,
secretAccessKey: string | null,
forcePathStyle: boolean,
allowPrivateNetwork: boolean,
};
export type RegistryTokenRequest = {
apiVersion: string,
kind: string,
scopes: App.Data.Registry.RegistryScope[],
task?: string,
};
}
namespace Security {
export type AuditLogOverviewData = {
verification: App.Data.Security.AuditVerificationData | null,
entries: number,
lastCheckpointAt: string | null,
publicKey: string,
keyId: string,
sinks: App.Data.Security.AuditSinkData[],
canAdd: boolean,
};
export type AuditSinkData = {
id: string,
kind: App.Enums.Audit.AuditSinkKind,
name: string,
target: string | null,
status: App.Enums.Audit.AuditSinkStatus,
behind: number,
deliveredThroughAt: string | null,
lastSuccessAt: string | null,
lastError: string | null,
failures: number,
nextAttemptAt: string | null,
privateNetwork: boolean,
objectLock: boolean | null,
retentionDays: number | null,
lockMode: string | null,
};
export type AuditVerificationData = {
ok: boolean,
mode: string,
checkedAt: string,
entries: number,
verifiedUpTo: string | null,
problem: string | null,
problemEntry: number | null,
};
export type ClientCertificatesOverviewData = {
mode: string,
changedAt: string | null,
caFingerprint: string | null,
caExpiresAt: string | null,
lifetimeDays: number,
servers: App.Data.Security.ServerCertificateReadinessData[],
readyCount: number,
blockingCount: number,
};
export type ServerCertificateReadinessData = {
id: string,
name: string,
team: string,
state: string,
ready: boolean,
fingerprint: string | null,
expiresAt: string | null,
lastPresentedAt: string | null,
lastMissingAt: string | null,
reissueUntil: string | null,
canAllowReissue: boolean,
};
}
namespace Servers {
export type CreateEnrollmentData = {
roles: string[],
name: string | null,
};
export type DeviceData = {
kind: App.Enums.DeviceKind,
detected: App.Enums.DeviceKind,
overridden: boolean,
model: string | null,
provider: App.Enums.HostingProvider | null,
providerName: string | null,
virtualized: boolean,
};
export type DmiFactsData = {
sysVendor: string | null,
productName: string | null,
productFamily: string | null,
boardVendor: string | null,
boardName: string | null,
biosVendor: string | null,
chassisType: number | null,
};
export type EnrollmentData = {
id: string,
name: string | null,
roles: App.Enums.ServerRole[],
expiresAt: string,
expired: boolean,
server: App.Data.Servers.ServerData | null,
};
export type HardwareFactsData = {
cpuModel: string | null,
cpuVendor: string | null,
dmi: App.Data.Servers.DmiFactsData | null,
deviceTreeModel: string | null,
virtualization: string | null,
osName: string | null,
kernel: string | null,
timezone: string | null,
battery: boolean | null,
hostModel: string | null,
};
export type HealthCheckData = {
key: string,
label: string,
state: App.Enums.HealthCheckState,
value: string | null,
detail: string | null,
};
export type HostFactsData = {
hostname: string | null,
os: string | null,
arch: string | null,
cpus: number | null,
memoryMb: number | null,
hardware: App.Data.Servers.HardwareFactsData | null,
};
export type LinkQualityData = {
latencyMs: number | null,
measuredAt: string | null,
error: string | null,
latencyHistory: (number| null)[],
probes: number,
failedProbes: number,
downloadMbps: number | null,
uploadMbps: number | null,
throughputAt: string | null,
};
export type LinkSpeedData = {
from: string,
to: string,
mbps: number,
};
export type LocationData = {
label: string,
latitude: number,
longitude: number,
countryCode: string | null,
source: string,
confirmed: boolean,
};
export type MeshLinkData = {
from: string,
to: string,
path: App.Enums.Mesh.MeshPath,
relay: string | null,
latencyMs: number | null,
speeds: App.Data.Servers.LinkSpeedData[],
};
export type MeshOverviewData = {
enabled: boolean,
mode: App.Enums.Mesh.MeshMode | null,
builtInAvailable: boolean,
canManage: boolean,
loginServer: string | null,
error: string | null,
errorAt: string | null,
enabledAt: string | null,
};
export type MeshPeerData = {
ip: string,
serverId: string | null,
name: string | null,
isControlPlane: boolean,
online: boolean,
path: App.Enums.Mesh.MeshPath,
relay: string | null,
lastHandshake: string | null,
quality: App.Data.Servers.LinkQualityData | null,
};
export type ReplaceableServerData = {
id: string,
name: string,
status: App.Enums.ServerStatus,
roles: App.Enums.ServerRole[],
labels: Record<string, string>,
isControlPlane: boolean,
lastSeenAt: string | null,
replacedBy: string | null,
replacedAt: string | null,
};
export type ReplacementPlanItemData = {
instanceId: string,
name: string,
stateful: boolean,
snapshot: string | null,
backupTakenAt: string | null,
reason: string | null,
};
export type RetiredVolumeData = {
id: string,
label: string,
name: string,
mountPath: string,
reason: string,
status: App.Enums.VolumeRetirementStatus,
retainUntil: string,
needsConfirmation: boolean,
confirmedAt: string | null,
requestedAt: string | null,
deletedAt: string | null,
message: string | null,
};
export type RoleHintData = {
id: string,
tone: App.Enums.Servers.RoleHintTone,
title: string,
body: string,
docs: string | null,
};
export type RuntimeCapabilityData = {
name: App.Enums.Servers.RuntimeCapability,
state: App.Enums.Servers.CapabilityState,
message: string | null,
};
export type ServerData = {
id: string,
name: string,
status: App.Enums.ServerStatus,
roles: App.Enums.ServerRole[],
isControlPlane: boolean,
daemonVersion: string | null,
lastSeenAt: string | null,
mesh: App.Data.Servers.ServerMeshData | null,
workloads: number,
device: App.Data.Servers.DeviceData | null,
location: App.Data.Servers.LocationData | null,
specs: App.Data.Servers.ServerSpecsData | null,
};
export type ServerDetailData = {
id: string,
name: string,
status: App.Enums.ServerStatus,
roles: App.Enums.ServerRole[],
isControlPlane: boolean,
daemonVersion: string | null,
protocolVersion: string | null,
signingKeyFingerprint: string | null,
facts: App.Data.Servers.HostFactsData,
desiredVersion: number,
observedVersion: number,
lastSeenAt: string | null,
enrolledAt: string | null,
approvedAt: string | null,
revokedAt: string | null,
canApprove: boolean,
canRevoke: boolean,
device: App.Data.Servers.DeviceData | null,
location: App.Data.Servers.LocationData | null,
specs: App.Data.Servers.ServerSpecsData | null,
canUpdateAppearance: boolean,
};
export type ServerHealthData = {
state: App.Enums.HealthCheckState,
collectedAt: string | null,
stale: boolean,
checks: App.Data.Servers.HealthCheckData[],
};
export type ServerLocationInputData = {
label: string,
latitude: number,
longitude: number,
countryCode: string | null,
};
export type ServerMeshData = {
state: App.Enums.Mesh.MeshMemberState,
ip: string | null,
pathToControlPlane: App.Enums.Mesh.MeshPath | null,
message: string | null,
checkedAt: string | null,
};
export type ServerPlacementData = {
labels: Record<string, string>,
allowPreviews: boolean,
allowProduction: boolean,
canUpdate: boolean,
allocatedMemoryMb: number,
schedulableMemoryMb: number | null,
allocatedCpus: number,
schedulableCpus: number | null,
workloads: App.Data.Servers.ServerWorkloadData[],
measureLinks: boolean,
};
export type ServerReplacementData = {
id: string,
status: App.Enums.ServerReplacementStatus,
oldServer: string,
newServer: string,
newServerId: string,
services: {
instance: string,
name: string,
kind: string,
restore: {
snapshot: string,
takenAt: string | null,
} | null,
status: string,
message: string | null,
}[],
recovery: boolean,
error: string | null,
createdAt: string | null,
finishedAt: string | null,
};
export type ServerRuntimeData = {
docker: App.Enums.Servers.DockerMode,
guard: boolean,
capabilities: App.Data.Servers.RuntimeCapabilityData[],
reportedAt: string,
hostOs: App.Enums.Servers.HostOs | null,
engine: App.Enums.Servers.DockerEngine | null,
buildPlatforms: string[] | null,
};
export type ServerSpecsData = {
cpus: number | null,
memoryMb: number | null,
diskTotalMb: number | null,
arch: string | null,
cpuModel: string | null,
osName: string | null,
};
export type ServerWorkloadData = {
workloadId: string,
serviceId: string,
serviceName: string,
environmentId: string,
environmentName: string,
projectId: string,
projectName: string,
deploymentId: string,
slot: number,
role: App.Enums.WorkloadRole,
phase: App.Enums.WorkloadPhase,
active: boolean,
memoryMb: number,
cpus: number,
};
export type SmallServerHintData = {
serverId: string,
serverName: string,
cpus: number,
memoryMb: number,
};
export type UpdateServerAppearanceData = {
device?: App.Enums.DeviceKind | null,
location?: App.Data.Servers.ServerLocationInputData | null,
};
export type UpdateServerRolesData = {
roles: string[],
};
export type UpdateServerSettingsData = {
labels: Record<string, string>,
allowPreviews: boolean,
allowProduction: boolean,
measureLinks: boolean | null,
};
export type UseOwnTailscaleData = {
loginServer: string,
authKey: string,
};
}
namespace Services {
export type AddVolumeData = {
mountPath: string,
};
export type BuildSettings = {
builder: string,
dockerfilePath: string | null,
installCommand: string | null,
buildCommand: string | null,
railpackVersion: string | null,
watchPaths: string[],
disabledDecisions: string[],
generatedDockerfile: string | null,
};
export type CreateServiceData = {
name: string,
kind: App.Enums.ServiceKind,
source: Record<string, any> | null,
slug: string | null,
icon: string | null,
parentServiceId: string | null,
port: number | null,
startCommand: string[] | null,
};
export type DatabaseSource = {
engine: App.Enums.DatabaseEngine,
version: string,
};
export type DeploySettings = {
port: number | null,
startCommand: string[] | null,
releaseCommand: string[] | null,
seedCommand: string[] | null,
healthcheck: App.Data.Services.HealthcheckSettings,
drainSeconds: number,
entrypoint: string[] | null,
capabilities: string[],
schedule: App.Data.Services.ScheduleSettings | null,
};
export type GitSource = {
repositoryId: number,
fullName: string,
installationId: number | null,
rootDirectory: string,
provider: string,
cloneUrl: string | null,
branch: string | null,
};
export type HealthcheckSettings = {
type: App.Enums.HealthcheckType,
path: string,
expectStatus: string,
startPeriodSeconds: number,
intervalSeconds: number,
timeoutSeconds: number,
failureThreshold: number,
};
export type ImageSource = {
reference: string,
};
export type PreviewScalingData = {
scaling: App.Data.Services.ScalingSettings,
};
export type RenameServiceData = {
name: string,
};
export type ScalingSettings = {
replicas: number,
cpus: number,
memoryMb: number,
placementLabels: Record<string, string>,
strategy: App.Enums.PlacementStrategy,
allowServers: string[],
denyServers: string[],
};
export type ScheduleSettings = {
cron: string,
timeZone: string,
};
export type ServicePlanData = {
status: string,
commitSha: string | null,
branch: string | null,
requestedAt: string | null,
finishedAt: string | null,
summary: App.Data.Protocol.V1.BuildSummary | null,
error: string | null,
failureClass: string | null,
};
export type UpdateServiceSettingsData = {
build?: App.Data.Services.BuildSettings,
deploy?: App.Data.Services.DeploySettings,
scaling?: App.Data.Services.ScalingSettings,
enabled?: boolean,
branchOverride?: string | null,
};
}
namespace Sessions {
export type AuthorizeSessionData = {
socketId: string,
ticket: string,
};
export type ConsoleTabData = {
available: boolean,
unavailableReason: string | null,
replicas: App.Data.Sessions.SessionReplicaData[],
shells: string[],
record: boolean,
passwordRequired: boolean,
idleMinutes: number,
maxMinutes: number,
sessions: App.Data.Sessions.SessionHistoryItemData[],
};
export type DataTabData = {
available: boolean,
unavailableReason: string | null,
engine: string,
engineLabel: string,
dialect: 'sql' | 'redis' | 'mongo',
replica: App.Data.Sessions.SessionReplicaData | null,
passwordRequired: boolean,
idleMinutes: number,
history: App.Data.Sessions.QueryHistoryItemData[],
};
export type OpenSessionData = {
workload: string | null,
shell: string | null,
cols: number,
rows: number,
};
export type OpenedSessionData = {
id: string,
kind: App.Enums.Sessions.InteractiveSessionKind,
channel: string,
ticket: string,
key: string,
idleSeconds: number,
maxSeconds: number,
record: boolean,
replica: App.Data.Sessions.SessionReplicaData,
shell: string | null,
engine: string | null,
};
export type QueryHistoryItemData = {
id: string,
statement: string | null,
status: App.Enums.Sessions.DatabaseQueryStatus,
allowWrites: boolean,
truncated: boolean,
rows: number | null,
durationMs: number | null,
createdAt: string,
};
export type SessionCommandData = {
command: unknown,
schema?: string | null,
table?: string | null,
page?: number,
pageSize?: number,
pattern?: string | null,
cursor?: string | null,
key?: string | null,
statement?: string | null,
allowWrites?: boolean,
reason?: string,
};
export type SessionHistoryItemData = {
id: string,
kind: App.Enums.Sessions.InteractiveSessionKind,
status: App.Enums.Sessions.InteractiveSessionStatus,
userName: string,
isMine: boolean,
replicaLabel: string,
serverName: string | null,
shell: string | null,
openedAt: string,
endedAt: string | null,
durationSeconds: number | null,
endReason: string | null,
exitCode: number | null,
canEnd: boolean,
hasRecording: boolean,
};
export type SessionReplicaData = {
workload: string,
slot: number,
label: string,
serverId: string,
serverName: string,
phase: string,
};
}
namespace Settings {
export type NetworkMemberData = {
id: string,
name: string,
isControlPlane: boolean,
online: boolean,
mesh: App.Data.Servers.ServerMeshData,
};
}
namespace Specs {
export type ChangeItem = {
path: string,
kind: App.Enums.ChangeKind,
effect: App.Enums.ChangeEffect,
promotion: App.Enums.PromotionClass,
label: string,
serviceId: string | null,
before: string | null,
after: string | null,
};
export type ConnectionSpec = {
id: string,
fromServiceId: string,
toServiceId: string,
mode: App.Enums.ConnectionMode,
style: App.Enums.ConnectionStyle,
};
export type DomainSpec = {
id: string,
hostname: string,
kind: App.Enums.DomainKind,
redirectTo: string | null,
};
export type EnvironmentMetaSpec = {
id: string,
slug: string,
name: string,
kind: App.Enums.EnvironmentKind,
projectId: string,
projectSlug: string,
triggers: App.Data.Environments.EnvironmentTriggers,
branch: string | null,
};
export type EnvironmentSpec = {
environment: App.Data.Specs.EnvironmentMetaSpec,
services: Record<string, App.Data.Specs.ServiceSpec>,
connections: Record<string, App.Data.Specs.ConnectionSpec>,
sharedVariables: Record<string, App.Data.Specs.VariableSpec>,
sharedServices: Record<string, string>,
};
export type ServiceSpec = {
id: string,
instanceId: string,
slug: string,
name: string,
kind: App.Enums.ServiceKind,
parentId: string | null,
source: Record<string, any>,
icon: string | null,
enabled: boolean,
branchOverride: string | null,
build: App.Data.Services.BuildSettings,
deploy: App.Data.Services.DeploySettings,
scaling: App.Data.Services.ScalingSettings,
variables: Record<string, App.Data.Specs.VariableSpec>,
connectionVariables: Record<string, App.Data.Specs.VariableSpec>,
sharedVariableIds: string[],
domains: Record<string, App.Data.Specs.DomainSpec>,
volumes: Record<string, App.Data.Specs.VolumeSpec>,
};
export type StagedChanges = {
items: App.Data.Specs.ChangeItem[],
services: Record<string, App.Enums.ChangeEffect>,
};
export type VariableSpec = {
id: string,
key: string,
type: App.Enums.VariableType,
version: number,
exposure: App.Enums.VariableExposure,
ciphertext: string,
layer: string,
generated: boolean,
references: string[],
connectionId: string | null,
withheld: boolean,
};
export type VolumeSpec = {
id: string,
mountPath: string,
};
}
namespace StagedChanges {
export type PromotedImageData = {
serviceId: string,
serviceName: string,
commitSha: string,
deploymentId: string,
fromEnvironment: string,
};
export type RevisionData = {
id: string,
number: number,
message: string | null,
createdAt: string,
author: string | null,
current: boolean,
};
export type StagedChangesData = {
projectId: string,
projectName: string,
environmentId: string,
environmentName: string,
count: number,
neverDeployed: boolean,
revision: number | null,
groups: App.Data.StagedChanges.StagedServiceData[],
blockers: string[],
canDeploy: boolean,
environmentKind: App.Enums.EnvironmentKind,
promotedImages: App.Data.StagedChanges.PromotedImageData[],
configFile: App.Data.ConfigAsCode.StagedConfigFileData | null,
};
export type StagedServiceData = {
serviceId: string | null,
name: string,
slug: string | null,
effect: App.Enums.ChangeEffect,
items: App.Data.Specs.ChangeItem[],
};
}
namespace Teams {
export type AcceptInvitationData = {
name: string,
password: string,
};
export type ChangeMemberRoleData = {
role: App.Enums.TeamRole,
};
export type ConsoleRecordingData = {
enabled: boolean,
};
export type CreateTeamData = {
name: string,
};
export type InvitationData = {
id: string,
email: string,
role: App.Enums.TeamRole,
invitedBy: string | null,
expiresAt: string,
expired: boolean,
};
export type InvitationPreviewData = {
teamName: string,
email: string,
role: App.Enums.TeamRole,
invitedBy: string | null,
hasAccount: boolean,
};
export type InviteMemberData = {
email: string,
role: App.Enums.TeamRole,
};
export type InviteMembersData = {
role: App.Enums.TeamRole,
email: string | null,
emails: string | null,
};
export type MemberData = {
id: string,
name: string,
email: string,
role: App.Enums.TeamRole,
isCurrentUser: boolean,
canEdit: boolean,
canRemove: boolean,
grants: App.Enums.Permission[],
grantable: App.Enums.Permission[],
};
export type MemberPermissionsData = {
permissions: string[] | null,
};
export type TeamContextData = {
current: App.Data.Teams.TeamData | null,
all: App.Data.Teams.TeamData[],
permissions: App.Enums.Permission[],
canCreateTeam: boolean,
};
export type TeamData = {
id: string,
name: string,
personal: boolean,
role: App.Enums.TeamRole | null,
};
export type TeamSettingsData = {
requireServerApproval: boolean,
productionSecretsMustDiffer: boolean,
previewCloneLimitMb: number | null,
recordConsoleSessions: boolean,
generateDockerfiles: boolean,
};
}
namespace Templates {
export type TemplateInputData = {
id: string,
label: string,
type: 'text' | 'email' | 'domain' | 'repository',
optional: boolean,
description: string | null,
placeholder: string | null,
};
export type TemplateSummaryData = {
id: string,
name: string,
tagline: string,
description: string,
category: string,
logo: string,
website: string | null,
license: string | null,
inputs: App.Data.Templates.TemplateInputData[],
preview: App.Data.Blueprints.BlueprintPreviewData,
needsRepository: boolean,
};
export type UseTemplateData = {
name: string | null,
inputs: Record<string, string>,
repository: string | null,
branch: string | null,
};
}
namespace Transfers {
export type ChunkReceiptData = {
index: number,
received: number,
chunks: number,
};
export type DependentServiceData = {
id: string,
name: string,
};
export type DownloadLinkData = {
url: string,
expiresAt: string,
};
export type ExportRunData = {
id: string,
subject: App.Enums.Transfers.DataSubject,
source: App.Enums.Transfers.ExportSource,
snapshot: string | null,
volume: App.Data.Transfers.TransferVolumeData | null,
status: App.Enums.Transfers.ExportRunStatus,
fileName: string,
sizeBytes: number,
format: string | null,
compressed: boolean,
error: string | null,
createdAt: string | null,
readyAt: string | null,
expiresAt: string | null,
};
export type ImportInspectionData = {
id: string,
status: unknown,
empty: boolean | null,
tables: number | null,
sizeBytes: number | null,
serverVersion: string | null,
rootUid: number | null,
rootGid: number | null,
sourceVersion: string | null,
error: string | null,
};
export type ImportResultData = {
tables: number,
files: number,
sizeBytes: number,
serverVersion: string | null,
sourceVersion: string | null,
format: string | null,
warnings: string[],
durationMs: number,
};
export type ImportRunData = {
id: string,
subject: App.Enums.Transfers.DataSubject,
status: App.Enums.Transfers.ImportRunStatus,
sourceKind: App.Enums.Transfers.ImportSourceKind,
sourceLabel: string,
replace: boolean,
stopDependents: boolean,
volume: App.Data.Transfers.TransferVolumeData | null,
step: string | null,
result: App.Data.Transfers.ImportResultData | null,
error: string | null,
createdAt: string | null,
startedAt: string | null,
finishedAt: string | null,
safetySnapshot: boolean,
transferId: string | null,
};
export type ServiceTransfersData = {
serviceInstanceId: string,
serviceName: string,
confirmationText: string,
subject: App.Enums.Transfers.DataSubject | null,
engine: string | null,
engineLabel: string | null,
engineVersion: string | null,
importSupported: boolean,
unavailableReason: string | null,
canImport: boolean,
canExport: boolean,
hasDestination: boolean,
volumes: App.Data.Transfers.TransferVolumeData[],
dependents: App.Data.Transfers.DependentServiceData[],
imports: App.Data.Transfers.ImportRunData[],
exports: App.Data.Transfers.ExportRunData[],
urls: {
self: string,
transfers: string,
inspect: string,
imports: string,
exports: string,
},
chunkBytes: number,
maxBytes: number,
};
export type TransferData = {
id: string,
purpose: App.Enums.Transfers.TransferPurpose,
direction: App.Enums.Transfers.TransferDirection,
status: App.Enums.Transfers.TransferStatus,
fileName: string,
sizeBytes: number,
chunkBytes: number,
chunks: number,
receivedChunks: number[],
format: string | null,
compressed: boolean,
sourceVersion: string | null,
detail: string | null,
error: string | null,
createdAt: string | null,
expiresAt: string | null,
entries: string[],
topFolder: string | null,
};
export type TransferVolumeData = {
id: string,
mountPath: string,
};
namespace Inputs {
export type CreateTransferInput = {
purpose: string,
fileName: string,
sizeBytes: number,
volume: string | null,
};
export type InspectImportInput = {
volume: string | null,
sourceUrl: string | null,
};
export type OwnerInput = {
uid: number,
gid: number,
};
export type StartExportInput = {
volume: string | null,
snapshot: string | null,
};
export type StartImportInput = {
transfer: string | null,
sourceUrl: string | null,
replace: boolean,
stopDependents: boolean,
confirmation: string | null,
volume: string | null,
stripComponents: number,
owner: App.Data.Transfers.Inputs.OwnerInput | null,
};
}
}
namespace Updates {
export type ControlPlaneUpdateData = {
id: string,
fromVersion: string,
toVersion: string,
status: App.Enums.Updates.ControlPlaneUpdateStatus,
statusLabel: string,
skipBackup: boolean,
updateServers: boolean,
automatic: boolean,
backupStatus: App.Enums.Backups.BackupRunStatus | null,
backupFinishedAt: string | null,
fleetUpdateId: string | null,
progress: string | null,
error: string | null,
createdAt: string | null,
startedAt: string | null,
finishedAt: string | null,
};
export type FleetServerData = {
id: string,
name: string,
roles: string[],
status: App.Enums.ServerStatus,
isControlPlane: boolean,
daemonVersion: string | null,
protocolVersion: string | null,
outdated: boolean,
newer: boolean,
compatible: boolean,
};
export type FleetUpdateData = {
id: string,
toVersion: string,
status: App.Enums.Updates.FleetUpdateStatus,
error: string | null,
steps: App.Data.Updates.FleetUpdateStepData[],
createdAt: string | null,
finishedAt: string | null,
};
export type FleetUpdateStepData = {
position: number,
server: string | null,
serverName: string,
fromVersion: string | null,
status: App.Enums.Updates.FleetUpdateStepStatus,
progress: string | null,
error: string | null,
};
export type ReleaseCheckData = {
currentVersion: string,
channel: string,
available: App.Data.Updates.ReleaseData | null,
checkedAt: string | null,
error: string | null,
};
export type ReleaseData = {
version: string,
tag: string,
name: string,
notes: string,
url: string,
publishedAt: string | null,
prerelease: boolean,
dashboardImage: string | null,
daemonImage: string | null,
cannotInstallReason: string | null,
installerCommand: string,
};
export type StartControlPlaneUpdateInput = {
version: string,
skipBackup: boolean,
updateServers: boolean,
};
export type UpdatePreferencesData = {
channel: string,
autoUpdate: boolean,
canAutoUpdate: boolean,
windowStart: number,
windowEnd: number,
timezone: string,
};
export type UpdatePreferencesInput = {
channel: string,
autoUpdate: boolean,
};
}
namespace Variables {
export type EnvironmentOptionData = {
id: string,
name: string,
kind: App.Enums.EnvironmentKind,
};
export type ImportPreviewData = {
entries: App.Data.Variables.ImportPreviewEntryData[],
errors: {
line: number,
message: string,
}[],
warnings: {
line: number,
message: string,
}[],
};
export type ImportPreviewEntryData = {
key: string,
line: number,
suggestedType: App.Enums.VariableType,
browserInlined: boolean,
status: string,
existingSecret: boolean,
};
export type ImportResult = {
created: string[],
updated: string[],
warnings: {
line: number,
message: string,
}[],
};
export type RawEditResult = {
created: string[],
updated: string[],
removed: string[],
warnings: {
line: number,
message: string,
}[],
};
export type ReferenceScopeData = {
scope: string | null,
label: string,
keys: string[],
};
export type ServiceVariablesData = {
serviceSlug: string,
environmentId: string,
variables: App.Data.Variables.VariableRowData[],
shared: App.Data.Variables.SharedVariableRowData[],
system: App.Data.Variables.SystemVariableData[],
scopes: App.Data.Variables.ReferenceScopeData[],
environments: App.Data.Variables.EnvironmentOptionData[],
canWrite: boolean,
canRevealSecrets: boolean,
secretPlaceholder: string,
};
export type SharedVariableRowData = {
id: string,
key: string,
type: App.Enums.VariableType,
value: string | null,
preview: string | null,
errors: string[],
used: boolean,
};
export type SystemVariableData = {
key: string,
value: string,
};
export type VariableInput = {
key: string,
value: string,
type: App.Enums.VariableType,
targets: App.Data.Variables.VariableTargetData[],
exposureOverride: App.Enums.VariableExposure | null,
};
export type VariableRowData = {
id: string,
key: string,
type: App.Enums.VariableType,
value: string | null,
exposure: App.Enums.VariableExposure,
exposureOverridden: boolean,
browserInlined: boolean,
generated: boolean,
references: string[],
preview: string | null,
errors: string[],
connection: string | null,
overridden: boolean,
targets: App.Data.Variables.VariableTargetChipData[],
canEdit: boolean,
canReveal: boolean,
managedByFile: boolean,
};
export type VariableTargetChipData = {
environmentId: string | null,
label: string,
allPreviews: boolean,
branchPattern: string | null,
};
export type VariableTargetData = {
environmentId: string | null,
allPreviews: boolean,
branchPattern: string | null,
};
export type VariableUpdate = {
key?: string,
value?: string,
type?: App.Enums.VariableType,
targets?: App.Data.Variables.VariableTargetData[],
exposureOverride?: App.Enums.VariableExposure | null,
onlyEnvironment?: string,
};
}
}
namespace Enums {
export type ActorType = 'user' | 'server' | 'system';
export type ApiAbility = 'read' | 'deploy' | 'env:read' | 'env:write' | 'secrets:read' | 'domains:write' | 'data:transfer';
export type ApiTokenClient = 'dashboard' | 'cli';
export type AuditAction = 'instance.setup_token_created' | 'instance.owner_created' | 'instance.team_claimed' | 'instance.owner_two_factor_reset' | 'team.created' | 'team.member_invited' | 'team.invitation_revoked' | 'team.invitation_accepted' | 'team.member_role_changed' | 'team.member_removed' | 'instance.signing_key_created' | 'server.enrollment_created' | 'server.enrollment_canceled' | 'server.enrolled' | 'server.approved' | 'server.revoked' | 'server.volume_deletion_confirmed' | 'instance.mesh_enabled' | 'instance.mesh_own_tailscale_configured' | 'server.mesh_node_removed' | 'project.created' | 'project.imported' | 'project.deleted' | 'project.renamed' | 'project.secrets_policy_changed' | 'project.icon_changed' | 'environment.created' | 'environment.deployed' | 'environment.changes_discarded' | 'environment.updated' | 'environment.deleted' | 'environment.reverted' | 'environment.changes_promoted' | 'environment.auto_deploy_resumed' | 'deployment.promoted' | 'service.created' | 'service.removed' | 'service.renamed' | 'variable.created' | 'variable.updated' | 'variable.deleted' | 'variable.imported' | 'variable.secret_revealed' | 'connection.created' | 'connection.deleted' | 'domain.added' | 'domain.removed' | 'domain.www_redirect_changed' | 'instance.apps_domain_changed' | 'instance.dashboard_domain_started' | 'instance.dashboard_domain_cancelled' | 'instance.dashboard_domain_switched' | 'instance.dashboard_domain_reverted' | 'instance.dashboard_domain_reset' | 'deployment.rolled_back' | 'deployment.redeployed' | 'deployment.cancelled' | 'variable.raw_edited' | 'github.app_created' | 'github.app_disconnected' | 'github.installation_linked' | 'git.connection_created' | 'git.connection_authorized' | 'git.connection_disconnected' | 'git.connection_private_network_allowed' | 'git.host_keys_confirmed' | 'git.webhook_secret_revealed' | 'backup.destination_created' | 'backup.destination_updated' | 'backup.destination_deleted' | 'backup.destination_private_network_allowed' | 'backup.schedule_updated' | 'backup.started' | 'backup.restore_started' | 'data.import_started' | 'data.import_finished' | 'data.import_source_checked' | 'data.export_started' | 'data.export_downloaded' | 'backup.restore_swapped' | 'backup.volume_updated' | 'backup.final_backup_skipped' | 'backup.point_in_time_updated' | 'backup.verification_started' | 'service.volume_added' | 'server.replaced' | 'instance.recovery_finished' | 'backup.recovery_passphrase_set' | 'backup.recovery_kit_downloaded' | 'backup.recovery_kit_confirmed' | 'preview.settings_updated' | 'preview.strategy_changed' | 'preview.approved' | 'preview.destroyed' | 'preview.share_link_created' | 'preview.share_link_revoked' | 'preview.bypass_rotated' | 'instance.update_started' | 'instance.update_finished' | 'instance.fleet_update_started' | 'instance.fleet_update_finished' | 'instance.update_preferences_changed' | 'instance.dns_provider_changed' | 'server.shared_edge_changed' | 'domain.hsts_changed' | 'instance.registry_storage_configured' | 'instance.registry_storage_private_network_allowed' | 'instance.registry_storage_removed' | 'instance.registry_config_written' | 'server.settings_updated' | 'server.roles_updated' | 'server.appearance_updated' | 'server.replicas_rescheduled' | 'service.degraded' | 'session.console_started' | 'session.console_ended' | 'session.data_started' | 'session.data_ended' | 'database.statement_executed' | 'session.recording_viewed' | 'team.console_recording_changed' | 'team.member_permissions_changed' | 'api_token.created' | 'api_token.revoked' | 'api_token.cli_login_approved' | 'api_token.cli_login_denied' | 'variable.env_exported' | 'deployment.requested' | 'alerts.channel_created' | 'alerts.channel_updated' | 'alerts.channel_deleted' | 'audit.sink_created' | 'audit.sink_updated' | 'audit.sink_deleted' | 'alerts.rules_updated' | 'config_file.applied' | 'config_file.invalid' | 'config_file.exported' | 'config_file.settings_updated' | 'ai_provider.updated' | 'team.dockerfile_generation_changed' | 'dockerfile_generation.started' | 'dockerfile_generation.used' | 'dockerfile_generation.pull_request_opened' | 'instance.client_certificates_changed' | 'server.client_certificate_issued' | 'server.client_certificates_revoked' | 'server.client_certificate_reissue_allowed' | 'server.move_started' | 'server.move_item_finished' | 'server.move_item_retried' | 'server.move_cancelled' | 'server.move_completed' | 'instance.control_plane_move_started' | 'instance.control_plane_move_claimed' | 'instance.control_plane_move_approved' | 'instance.control_plane_move_cancelled' | 'instance.control_plane_handover_started' | 'instance.control_plane_handover_aborted' | 'instance.control_plane_handed_over' | 'instance.control_plane_taken_over' | 'instance.control_plane_move_finished';
export type BlueprintNoteKind = 'translated' | 'ignored' | 'refused' | 'warning';
export type CanvasNodeState = 'active' | 'progress' | 'failed' | 'idle' | 'warning';
export type CertStatus = 'issued' | 'pending' | 'failed';
export type ChangeEffect = 'none' | 'route' | 'restart' | 'rebuild' | 'new' | 'remove';
export type ChangeKind = 'added' | 'removed' | 'changed';
export type CliLoginStatus = 'pending' | 'approved' | 'denied' | 'consumed';
export type ClientCertificateMode = 'off' | 'verify' | 'require';
export type ConnectionMode = 'private' | 'public';
export type ConnectionStyle = 'url' | 'laravel' | 'parts';
export type DaemonFeature = 'linkProbe' | 'shipLogs' | 'pointInTime' | 'verifyRestore' | 'stopSpec' | 'consoleSessions' | 'dataSessions' | 'controlPlaneUrl' | 'gitProviders' | 'generatedDockerfile' | 'mongoClickhouse' | 'dnsProviders' | 'cronSchedule' | 'clientCertificates' | 'dataTransfer' | 'preparedVolumes';
export type DaemonNudge = 'state.changed' | 'task.available' | 'logs.requested';
export type DaemonTaskStatus = 'pending' | 'leased' | 'succeeded' | 'failed' | 'cancelled';
export type DaemonTaskType = 'build' | 'plan' | 'run_once' | 'backup' | 'restore' | 'backup_maintenance' | 'logs' | 'self_update' | 'backup_snapshots' | 'verify_restore' | 'session' | 'db_import' | 'volume_import' | 'db_export' | 'volume_export';
export type DashboardDomainStatus = 'pending' | 'ready' | 'active' | 'cancelled' | 'reverted';
export type DatabaseEngine = 'postgres' | 'mysql' | 'redis' | 'valkey' | 'mongodb' | 'clickhouse';
export type DeploymentFailureClass = 'plan_error' | 'build_error' | 'transient' | 'release_error' | 'runtime_error' | 'port_mismatch' | 'health_timeout' | 'config_error' | 'placement_error';
export type DeploymentStatus = 'queued' | 'building' | 'releasing' | 'rolling_out' | 'health_checking' | 'ready' | 'routing' | 'active' | 'failed' | 'cancelled' | 'superseded';
export type DeploymentStepName = 'plan' | 'build' | 'release' | 'rollout' | 'health' | 'route' | 'cleanup';
export type DeploymentStepStatus = 'running' | 'succeeded' | 'failed' | 'skipped';
export type DeploymentTrigger = 'push' | 'pr' | 'tag' | 'manual' | 'rollback' | 'promote' | 'config' | 'api';
export type DeviceKind = 'cloud' | 'dedicated' | 'tower' | 'minipc' | 'compact' | 'sbc' | 'nas' | 'laptop' | 'vm' | 'unknown';
export type DnsProvider = 'cloudflare' | 'hetzner' | 'route53' | 'digitalocean' | 'porkbun' | 'desec' | 'gandi' | 'ovh' | 'netcup';
export type DomainKind = 'generated' | 'custom' | 'deployment';
export type EnvironmentKind = 'production' | 'persistent' | 'preview';
export type FailureFixAction = 'set_port' | 'raise_health_timeout' | 'add_variable' | 'generate_dockerfile';
export type HealthCheckState = 'ok' | 'warning' | 'critical' | 'unknown';
export type HealthcheckType = 'http' | 'tcp' | 'none';
export type HostingProvider = 'hetzner' | 'digitalocean' | 'aws' | 'gcp' | 'azure' | 'linode' | 'vultr' | 'ovh' | 'scaleway' | 'oracle' | 'upcloud' | 'exoscale';
export type IngressCredentialPurpose = 'dns_token' | 'storage_key' | 'storage_password';
export type LogSourceKind = 'task' | 'deployment' | 'workload';
export type MetricRange = '1h' | '6h' | '24h' | '7d' | '30d' | '90d' | '1y';
export type Permission = 'team.manage' | 'servers.manage' | 'members.manage' | 'projects.manage' | 'deployments.create' | 'variables.write' | 'secrets.reveal' | 'containers.exec' | 'databases.query' | 'exposure.manage' | 'resources.view' | 'backups.manage' | 'alerts.manage';
export type PlacementStrategy = 'spread' | 'pack';
export type PreviewStrategy = 'new_empty' | 'clone' | 'share';
export type ProbeState = 'starting' | 'passing' | 'failing';
export type ProjectIconMode = 'automatic' | 'uploaded' | 'none';
export type PromotionClass = 'structural' | 'environment_specific' | 'never';
export type PromotionEntryType = 'service' | 'field' | 'variable' | 'shared_variable' | 'shared_usage' | 'volume' | 'connection' | 'triggers';
export type PromotionKind = 'created' | 'promoted';
export type ServerReplacementStatus = 'running' | 'completed' | 'failed';
export type ServerRole = 'edge' | 'runtime' | 'build' | 'registry';
export type ServerStatus = 'pending_approval' | 'online' | 'unreachable' | 'offline' | 'revoked';
export type ServerTab = 'overview' | 'metrics' | 'network' | 'settings';
export type ServiceKind = 'app' | 'image' | 'database' | 'worker' | 'cron';
export type SigningKeyStatus = 'active' | 'retired';
export type TeamRole = 'owner' | 'admin' | 'developer' | 'viewer';
export type VariableExposure = 'runtime' | 'build' | 'both';
export type VariableType = 'config' | 'secret';
export type VolumeRetirementStatus = 'retained' | 'requested' | 'deleted';
export type WorkloadCapability = 'CHOWN' | 'DAC_OVERRIDE' | 'FOWNER' | 'NET_BIND_SERVICE' | 'SETGID' | 'SETUID';
export type WorkloadPhase = 'pending' | 'pulling' | 'starting' | 'running' | 'healthy' | 'unhealthy' | 'draining' | 'exited' | 'failed' | 'removed';
export type WorkloadRole = 'serving' | 'draining';
namespace Alerts {
export type AlertChannelKind = 'email' | 'slack' | 'discord' | 'webhook';
export type AlertDeliveryEvent = 'fired' | 'repeated' | 'resolved' | 'test';
export type AlertDeliveryStatus = 'pending' | 'sent' | 'failed';
export type AlertRule = 'deploy_failed' | 'service_unhealthy' | 'error_rate_high' | 'server_offline' | 'cpu_high' | 'memory_high' | 'disk_full' | 'certificate_expiring' | 'backup_failed';
export type AlertSeverity = 'warning' | 'critical';
export type AlertStatus = 'firing' | 'resolved';
}
namespace Audit {
export type AuditChainProblem = 'edited' | 'broken_link' | 'truncated' | 'forged_checkpoint' | 'checkpoint_mismatch';
export type AuditSinkKind = 'syslog' | 'webhook' | 's3';
export type AuditSinkStatus = 'pending' | 'delivering' | 'failing' | 'paused';
}
namespace Backups {
export type BackupAlertKind = 'backup' | 'backup_verify' | 'backup_overdue' | 'wal_archive';
export type BackupHealth = 'ok' | 'stale' | 'failing' | 'running' | 'none' | 'unconfigured';
export type BackupReason = 'scheduled' | 'manual' | 'pre_upgrade' | 'pre_restore' | 'pre_delete' | 'pre_deploy' | 'pre_move';
export type BackupRunStatus = 'pending' | 'running' | 'succeeded' | 'failed';
export type BackupSubject = 'database' | 'volume' | 'control_plane' | 'base_backup';
export type DeletionOutcome = 'deleted' | 'waiting_for_final_backup' | 'blocked';
export type DeletionRequestStatus = 'backing_up' | 'blocked' | 'ready' | 'overridden' | 'completed' | 'cancelled';
export type DestinationScope = 'team' | 'instance';
export type DestinationStatus = 'unverified' | 'checking' | 'ok' | 'failing';
export type RestoreMode = 'new_service' | 'in_place';
export type RestoreRunStatus = 'waiting_for_safety_backup' | 'waiting_for_target' | 'restoring' | 'succeeded' | 'failed';
export type SnapshotListingStatus = 'pending' | 'ready' | 'failed';
export type SnapshotSource = 'run' | 'repository';
export type VerificationReason = 'scheduled' | 'manual';
export type VerificationStatus = 'pending' | 'running' | 'passed' | 'failed';
export type VolumeConsistency = 'live' | 'pause';
export type WalArchiveState = 'active' | 'waiting' | 'failing';
}
namespace ConfigAsCode {
export type ConfigFileStatus = 'applied' | 'staged' | 'unchanged' | 'invalid' | 'missing';
export type ConfigTrust = 'trusted' | 'preview' | 'fork';
}
namespace Dockerfiles {
export type AiProvider = 'anthropic';
export type DockerfileConfidence = 'high' | 'medium' | 'low';
export type DockerfileGenerationStatus = 'reading' | 'drafting' | 'building' | 'starting' | 'verified' | 'failed' | 'cancelled';
export type DockerfileGenerationTrigger = 'plan_failed' | 'build_failed' | 'on_demand';
}
namespace Git {
export type DeploymentReportState = 'queued' | 'in_progress' | 'success' | 'failure' | 'cancelled';
export type GitAuthMethod = 'oauth' | 'token' | 'deploy_key' | 'none';
export type GitConnectionStatus = 'pending' | 'active' | 'needs_reauth';
export type GitEventSource = 'webhook' | 'polling';
export type GitProviderKind = 'github' | 'gitlab' | 'gitea' | 'git';
export type GitServerKind = 'gitlab' | 'gitea' | 'forgejo' | 'git';
export type RepositorySelection = 'all' | 'selected';
export type WebhookDeliveryStatus = 'received' | 'processed' | 'ignored' | 'failed';
export type WebhookHealth = 'healthy' | 'stale' | 'never';
export type WebhookReceipt = 'accepted' | 'duplicate' | 'invalid' | 'unauthorized';
}
namespace Mesh {
export type MeshCredentialPurpose = 'headscale_api_key' | 'tailscale_auth_key' | 'pre_auth_key';
export type MeshMemberState = 'off' | 'connecting' | 'connected' | 'error';
export type MeshMode = 'embedded' | 'byo';
export type MeshPath = 'direct' | 'relay' | 'idle';
}
namespace Moves {
export type ControlPlaneMoveStatus = 'waiting_for_server' | 'claimed' | 'joining' | 'joined' | 'moving' | 'ready_to_hand_over' | 'handing_over' | 'handover_ready' | 'handed_over' | 'cancelled';
export type MoveItemKind = 'stateless' | 'dump' | 'files';
export type MoveItemStatus = 'pending' | 'building' | 'backing_up' | 'stopping' | 'exporting' | 'preparing' | 'importing' | 'starting' | 'done' | 'failed' | 'rolling_back' | 'rolled_back' | 'cancelled' | 'skipped';
export type ServerMoveStatus = 'running' | 'ready' | 'cancelling' | 'completed' | 'cancelled';
}
namespace Previews {
export type ForkPolicy = 'off' | 'approval';
export type PreviewProvider = 'github' | 'gitlab' | 'gitea' | 'git';
export type PreviewStatus = 'skipped' | 'awaiting_approval' | 'queued' | 'preparing' | 'running' | 'failed' | 'closed' | 'expired';
}
namespace Servers {
export type CapabilityState = 'full' | 'degraded' | 'unavailable';
export type DockerEngine = 'docker-engine' | 'docker-desktop' | 'orbstack' | 'colima';
export type DockerMode = 'rootful' | 'rootless';
export type HostOs = 'linux' | 'darwin';
export type RoleHintTone = 'info' | 'warning';
export type RuntimeCapability = 'dockerAccess' | 'edgePorts' | 'clientAddresses' | 'firewall' | 'mesh' | 'portDiscovery' | 'workloads' | 'builds';
}
namespace Sessions {
export type DatabaseQueryStatus = 'sent' | 'ok' | 'error' | 'refused';
export type InteractiveSessionKind = 'console' | 'data';
export type InteractiveSessionStatus = 'pending' | 'open' | 'ended';
export type SessionEndReason = 'closed' | 'exited' | 'idle' | 'deadline' | 'disconnected' | 'ended' | 'never_connected' | 'refused' | 'error';
}
namespace Transfers {
export type DataSubject = 'database' | 'volume';
export type ExportRunStatus = 'preparing' | 'ready' | 'failed' | 'expired';
export type ExportSource = 'live' | 'snapshot';
export type ImportRunStatus = 'waiting_for_safety_backup' | 'holding' | 'importing' | 'succeeded' | 'failed' | 'cancelled';
export type ImportSourceKind = 'file' | 'url';
export type TransferDirection = 'upload' | 'download';
export type TransferPurpose = 'database_import' | 'volume_import' | 'database_export' | 'volume_export';
export type TransferStatus = 'receiving' | 'ready' | 'failed' | 'expired';
}
namespace Updates {
export type ControlPlaneUpdateStatus = 'pending' | 'backing_up' | 'updating' | 'succeeded' | 'failed' | 'rolled_back';
export type FleetUpdateStatus = 'running' | 'succeeded' | 'failed';
export type FleetUpdateStepStatus = 'pending' | 'updating' | 'succeeded' | 'failed' | 'rolled_back' | 'skipped';
export type UpdateScope = 'daemon' | 'control_plane';
}
}
}
declare namespace Illuminate {
export type CursorPaginator<TKey, TValue> = {
data: TKey extends string ? Record<TKey, TValue> : TValue[],
links: {
url: string | null,
label: string,
active: boolean,
}[],
meta: {
path: string,
per_page: number,
next_cursor: string | null,
next_page_url: string | null,
prev_cursor: string | null,
prev_page_url: string | null,
},
};
export type CursorPaginatorInterface<TKey, TValue> = Illuminate.CursorPaginator<TKey, TValue>;
export type LengthAwarePaginator<TKey, TValue> = {
data: TKey extends string ? Record<TKey, TValue> : TValue[],
links: {
url: string | null,
label: string,
active: boolean,
}[],
meta: {
total: number,
current_page: number,
first_page_url: string,
from: number | null,
last_page: number,
last_page_url: string,
next_page_url: string | null,
path: string,
per_page: number,
prev_page_url: string | null,
to: number | null,
},
};
export type LengthAwarePaginatorInterface<TKey, TValue> = Illuminate.LengthAwarePaginator<TKey, TValue>;
}
declare namespace Spatie {
namespace LaravelData {
export type CursorPaginatedDataCollection<TKey, TValue> = Illuminate.CursorPaginator<TKey, TValue>;
export type PaginatedDataCollection<TKey, TValue> = Illuminate.LengthAwarePaginator<TKey, TValue>;
}
}
