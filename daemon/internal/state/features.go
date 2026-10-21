package state

import "regexp"

// ---- Daemon features (capability gating) ----
//
// Decoding is strict on both sides, so a launchroomd that does not know an
// optional NodeState field refuses the whole document. Every daemon version
// therefore lists the optional protocol features it understands in
// Report.host.features, and the dashboard renders an optional field only
// for servers that listed its feature (docs/03 §10).

// DaemonFeature names an optional protocol feature (lowerCamel, ≤ 32 bytes).
// The grammar is open so newer daemons can announce features older
// dashboards ignore.
type DaemonFeature string

// Features of this protocol version.
const (
	// FeatureLinkProbe: system.linkProbe and Report.host.links (docs/05 §2.1).
	FeatureLinkProbe DaemonFeature = "linkProbe"
	// FeatureShipLogs: WorkloadSpec.shipLogs (runtime log shipping).
	FeatureShipLogs DaemonFeature = "shipLogs"
	// FeaturePointInTime: WorkloadSpec.walArchive, Report.walArchives, the
	// base_backup backup/restore kind (point-in-time recovery, docs/14 §10).
	FeaturePointInTime DaemonFeature = "pointInTime"
	// FeatureVerifyRestore: the verify_restore task (docs/14 §10.4).
	FeatureVerifyRestore DaemonFeature = "verifyRestore"
	// FeatureStopSpec: WorkloadSpec.stop (stop signal, timeout, no init; D42).
	FeatureStopSpec DaemonFeature = "stopSpec"
	// FeatureConsoleSessions / FeatureDataSessions: "session" tasks of that
	// kind (docs/03 §11); announced only when the local policy
	// (LR_INTERACTIVE_SESSIONS) allows the kind.
	FeatureConsoleSessions DaemonFeature = "consoleSessions"
	FeatureDataSessions    DaemonFeature = "dataSessions"
	// FeatureGitProviders: repo.provider gitlab/gitea, ssh:// clone URLs with
	// repo.knownHosts and BuildInputs.sshKey, and repo.ref (docs/19 §6).
	FeatureGitProviders DaemonFeature = "gitProviders"
	// FeatureControlPlaneURL: system.controlPlane and Report.host.controlPlane
	// (the dashboard's canonical origin, docs/03 §4.2).
	FeatureControlPlaneURL DaemonFeature = "controlPlaneUrl"
	// FeatureClientCertificates: routes[].clientAuth, the client-certificate
	// endpoint and ingress secret purpose client_auth (mTLS, D50, docs/03 §4.3).
	FeatureClientCertificates DaemonFeature = "clientCertificates"
	// FeatureCronSchedule: WorkloadSpec.schedule (scheduled cron services).
	FeatureCronSchedule DaemonFeature = "cronSchedule"
	// FeatureGeneratedDockerfile: BuilderSpec.dockerfile (inline Dockerfile),
	// PlanSpec.inspect with TaskCompletion.inspection, and RunOnceSpec.probe
	// with RunOnceResult.probe (docs/20 §6, D48).
	FeatureGeneratedDockerfile DaemonFeature = "generatedDockerfile"
)

// FeatureMongoClickHouse announces the mongodb and clickhouse engines in backup,
// restore, verify_restore and data session tasks (D47).
const FeatureMongoClickHouse DaemonFeature = "mongoClickhouse"

// FeatureDNSProviders announces ingress.dns providers beyond cloudflare/hetzner
// (route53, digitalocean, porkbun, desec, gandi, ovh, netcup) with their
// per-provider credential names (D47, docs/05 §5).
const FeatureDNSProviders DaemonFeature = "dnsProviders"

// FeatureDataTransfer announces the db_import, volume_import, db_export and
// volume_export tasks and the transfer chunk endpoints (D55, docs/14 §12);
// announced only when the local LR_DATA_TRANSFERS allows them.
const FeatureDataTransfer DaemonFeature = "dataTransfer"

// FeaturePreparedVolumes announces that NodeState volumes no workload mounts
// are created too (without an owner), so a service moving here from another
// server can have its volumes copied in before it runs (docs/14 §13.2, D56).
// Older daemons only create volumes a workload mounts.
const FeaturePreparedVolumes DaemonFeature = "preparedVolumes"

// SupportedFeatures is what this launchroomd announces in every report.
var SupportedFeatures = []DaemonFeature{FeatureDNSProviders, FeatureLinkProbe, FeatureShipLogs, FeaturePointInTime, FeatureVerifyRestore, FeatureStopSpec, FeatureGitProviders, FeatureControlPlaneURL, FeatureCronSchedule, FeatureGeneratedDockerfile, FeatureClientCertificates, FeatureMongoClickHouse, FeaturePreparedVolumes}

// maxFeatures bounds Report.host.features.
const maxFeatures = 64

var reDaemonFeature = regexp.MustCompile(`^[a-z][A-Za-z0-9]{0,31}$`)

// ProtocolSchema implements SchemaDescriber.
func (DaemonFeature) ProtocolSchema() ScalarSchema { return strSchema(reDaemonFeature, 0) }

// Validate checks the grammar.
func (v DaemonFeature) Validate() error {
	return checkPattern("daemon feature", string(v), reDaemonFeature, 0)
}

func validateFeatures(e *errs, p string, fs []DaemonFeature) {
	checkCount(e, p, len(fs), 0, maxFeatures)
	for i, f := range fs {
		e.check(idx(p, i), f)
	}
	unique(e, p, fs)
}
