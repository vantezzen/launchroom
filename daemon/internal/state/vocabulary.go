package state

import (
	"reflect"
	"slices"
	"strconv"
	"strings"
	"time"
)

// Vocabulary is what the dashboard checks with launchroomd's own values
// instead of copies: named identifier grammars (regex sources as the Go
// validators use them), forbidden mount targets, numeric limits, the
// daemon features and which protocol field or task needs which feature.
// schemagen writes it to protocol/schema/v1/vocabulary.json.
type Vocabulary struct {
	Grammars              map[string]string `json:"grammars"`
	ForbiddenMountTargets []string          `json:"forbiddenMountTargets"`
	Limits                map[string]int    `json:"limits"`
	// IntSets are the allowed values of integer enums the dashboard offers.
	IntSets       map[string][]int         `json:"intSets"`
	Constants     map[string]string        `json:"constants"`
	Features      []DaemonFeature          `json:"features"`
	FieldFeatures map[string]DaemonFeature `json:"fieldFeatures"`
	TaskFeatures  map[string]DaemonFeature `json:"taskFeatures"`
	// ValueFeatures are enum values that need a feature, by enum type.
	ValueFeatures map[string]map[string]DaemonFeature `json:"valueFeatures"`
	// HintCodes are the codes of TaskFailure.hints (FailureHint.code is a
	// slug on the wire, so they are not a schema enum).
	HintCodes []string `json:"hintCodes"`
}

// ValueFeatures names the feature a server must announce before a document
// may use an enum value, by the enum's Go type.
var ValueFeatures = map[string]map[string]DaemonFeature{
	"GitProvider":    {string(GitLab): FeatureGitProviders, string(Gitea): FeatureGitProviders},
	"DatabaseEngine": {string(EngineMongoDB): FeatureMongoClickHouse, string(EngineClickHouse): FeatureMongoClickHouse},
	"BackupKind":     {string(BackupBaseBackup): FeaturePointInTime},
	"DNSProvider": {
		string(DNSRoute53): FeatureDNSProviders, string(DNSDigitalOcean): FeatureDNSProviders, string(DNSPorkbun): FeatureDNSProviders,
		string(DNSDesec): FeatureDNSProviders, string(DNSGandi): FeatureDNSProviders, string(DNSOVH): FeatureDNSProviders,
		string(DNSNetcup): FeatureDNSProviders,
	},
}

// TaskFeatures names the feature a server must announce before it may be
// sent a task: by task type, or "type:kind" for session and access tasks.
var TaskFeatures = map[string]DaemonFeature{
	string(TaskVerifyRestore):                           FeatureVerifyRestore,
	string(TaskDbImport):                                FeatureDataTransfer,
	string(TaskVolumeImport):                            FeatureDataTransfer,
	string(TaskDbExport):                                FeatureDataTransfer,
	string(TaskVolumeExport):                            FeatureDataTransfer,
	string(TaskInventory):                               FeatureInventory,
	string(TaskInspectContainer):                        FeatureContainerInspection,
	string(TaskSession) + ":" + string(SessionConsole):  FeatureConsoleSessions,
	string(TaskSession) + ":" + string(SessionData):     FeatureDataSessions,
	string(TaskAccess) + ":" + string(AccessSSH):        FeatureRemoteAccessSSH,
	string(TaskAccess) + ":" + string(AccessDatabase):   FeatureRemoteAccessDatabase,
	string(TaskAccess) + ":" + string(AccessVolume):     FeatureRemoteAccessVolume,
	string(TaskCleanup):                                 FeatureCleanupTask,
	string(TaskCertRetry):                               FeatureCertificateRetry,
	string(TaskSession) + ":" + string(SessionRecovery): FeatureRecoverySessions,
}

// AllFeatures are the features of this protocol version, including those a
// launchroomd announces only when its local policy or host allows them.
var AllFeatures = []DaemonFeature{
	FeatureLinkProbe, FeatureShipLogs, FeaturePointInTime, FeatureVerifyRestore, FeatureStopSpec,
	FeatureConsoleSessions, FeatureDataSessions, FeatureGitProviders, FeatureControlPlaneURL,
	FeatureClientCertificates, FeatureCronSchedule, FeatureGeneratedDockerfile, FeatureConfigFiles,
	FeatureMongoClickHouse, FeatureDNSProviders, FeatureDataTransfer, FeaturePreparedVolumes,
	FeatureRequestAnalytics, FeatureRemoteAccessSSH, FeatureRemoteAccessDatabase, FeatureRemoteAccessVolume,
	FeatureInventory, FeatureContainerInspection, FeatureWaf, FeatureServiceStop, FeaturePhpExtensions,
	FeatureSharedVolumes,
	FeatureProbeHost, FeatureLivenessRestart, FeatureVolumeInitOwner, FeatureReleaseMounts, FeatureRecoverySessions,
	FeatureWatchdog, FeatureCleanupTask, FeatureCertificateRetry,
}

// ProtocolVocabulary returns the vocabulary of this protocol version;
// fieldFeatures come from the `lr:"feature=…"` tags on the documents' fields.
func ProtocolVocabulary() Vocabulary {
	fields := map[string]DaemonFeature{}
	for _, d := range Documents() {
		t := reflect.TypeOf(d.Value).Elem()
		collectFieldFeatures(t, t.Name(), fields, map[reflect.Type]bool{})
	}
	for typ, spec := range SpecTypes() {
		collectFieldFeatures(reflect.TypeOf(spec), "Task("+string(typ)+").spec", fields, map[reflect.Type]bool{})
	}
	features := slices.Clone(AllFeatures)
	slices.Sort(features)
	return Vocabulary{
		Grammars: map[string]string{
			"ulid":                 ulid,
			"publicId":             `^[a-z]{2,4}_` + ulid + `$`,
			"containerPath":        reContainerPath.String(),
			"relPath":              reRelPath.String(),
			"httpPath":             reHTTPPath.String(),
			"envName":              EnvNamePattern,
			"hostName":             reHostName.String(),
			"hostname":             reHostname.String(),
			"slug":                 reSlug.String(),
			"base64":               reBase64.String(),
			"hex64":                reHex64.String(),
			"imageRef":             reImageRef.String(),
			"databaseIdent":        reDatabaseIdent.String(),
			"s3Endpoint":           reS3Endpoint.String(),
			"bucket":               reBucket.String(),
			"region":               reRegion.String(),
			"objectPrefix":         reObjectPrefix.String(),
			"semver":               reSemver.String(),
			"gitSha":               reGitSHA.String(),
			"cron":                 reCronExpr.String(),
			"timezone":             reTimezone.String(),
			"daemonFeature":        reDaemonFeature.String(),
			"wafPathPrefix":        reWafPathPrefix.String(),
			"forwardAuthUrl":       reForwardAuthURL.String(),
			"snapshotId":           reSnapshotID.String(),
			"walSegment":           reWalSegment.String(),
			"backupTag":            reBackupTag.String(),
			"label":                reHostLabel.String(),
			"sshFingerprint":       reSSHFingerprint.String(),
			"factText":             reFactText.String(),
			"wafLabel":             reWafLabel.String(),
			"joinToken":            reJoinToken.String(),
			"ed25519Key":           reEd25519Key.String(),
			"x25519Key":            reX25519Key.String(),
			"socketId":             reSocketID.String(),
			"teamMirrorRepository": reTeamMirrorRepo.String(),
			"composerPackage":      reComposerPackage.String(),
			"phpExtensionIdent":    rePhpExtensionIdent.String(),
			"email":                reEmail.String(),
		},
		ForbiddenMountTargets: slices.Clone(forbiddenContainerPaths),
		Limits: map[string]int{
			"meshPortMin":            MeshPortMin,
			"meshPortMax":            MeshPortMax,
			"registryMeshPort":       RegistryMeshPort,
			"certStorageMeshPort":    CertStorageMeshPort,
			"linkProbeMeshPort":      LinkProbeMeshPort,
			"wafRuleMin":             WafRuleMin,
			"wafRuleMax":             WafRuleMax,
			"wafExcludableMin":       WafExcludableMin,
			"wafExcludableMax":       WafExcludableMax,
			"wafExclusions":          MaxWafExclusions,
			"listItems":              maxListItems,
			"argv":                   maxArgv,
			"argLength":              maxArgLen,
			"envVars":                maxEnvVars,
			"envValueBytes":          maxEnvValueLen,
			"messageBytes":           MaxMessageLen,
			"logLineBytes":           MaxLogLineLen,
			"meshAllowFrom":          tagInt(CertStorageMeshSpec{}, "AllowFrom", "maxItems"),
			"routeUpstreams":         tagInt(RouteSpec{}, "Upstreams", "maxItems"),
			"linkProbePeers":         tagInt(LinkProbeSpec{}, "Peers", "maxItems"),
			"healthDurationSeconds":  int(MaxHealthDuration / time.Second),
			"restartAfterMinSeconds": int(MinRestartAfter / time.Second),
			"restartAfterMaxSeconds": int(MaxRestartAfter / time.Second),
			"stopTimeoutSeconds":     int(MaxStopTimeout / time.Second),
			"certRetryHosts":         MaxCertRetryHosts,
		},
		IntSets: map[string][]int{
			"wafInspectBodyKib": tagInts(RouteWaf{}, "InspectBodyKiB", "enum"),
		},
		Constants: map[string]string{
			"registryAddress": RegistryAddress,
			"apiVersion":      APIVersion,
		},
		Features:      features,
		FieldFeatures: fields,
		TaskFeatures:  TaskFeatures,
		ValueFeatures: ValueFeatures,
		HintCodes:     slices.Sorted(slices.Values(hintCodes)),
	}
}

// tagInt reads one numeric jsonschema tag option of a field, so a limit the
// schema states is exported without restating it.
func tagInt(v any, field, option string) int {
	values := tagInts(v, field, option)
	if len(values) != 1 {
		panic("state: " + field + " has no single " + option + " tag")
	}
	return values[0]
}

func tagInts(v any, field, option string) []int {
	f, ok := reflect.TypeOf(v).FieldByName(field)
	if !ok {
		panic("state: no field " + field)
	}
	var out []int
	for opt := range strings.SplitSeq(f.Tag.Get("jsonschema"), ",") {
		if raw, ok := strings.CutPrefix(opt, option+"="); ok {
			n, err := strconv.Atoi(raw)
			if err != nil {
				panic("state: " + field + " tag " + opt)
			}
			out = append(out, n)
		}
	}
	return out
}

// collectFieldFeatures walks t's fields; a tagged field is recorded as
// "<Document>.<json path>" ("NodeState.workloads[].schedule"), task spec
// fields as "Task(<type>).spec.<json path>".
func collectFieldFeatures(t reflect.Type, path string, out map[string]DaemonFeature, seen map[reflect.Type]bool) {
	for t.Kind() == reflect.Pointer || t.Kind() == reflect.Slice || t.Kind() == reflect.Array || t.Kind() == reflect.Map {
		if t.Kind() == reflect.Slice || t.Kind() == reflect.Array {
			path += "[]"
		}
		t = t.Elem()
	}
	if t.Kind() != reflect.Struct || seen[t] {
		return
	}
	seen[t] = true
	defer delete(seen, t)
	for i := range t.NumField() {
		f := t.Field(i)
		if !f.IsExported() {
			continue
		}
		name, _, _ := strings.Cut(f.Tag.Get("json"), ",")
		if name == "-" {
			continue
		}
		p := path
		if !f.Anonymous || name != "" {
			if name == "" {
				name = f.Name
			}
			p = path + "." + name
		}
		if feat, ok := strings.CutPrefix(f.Tag.Get("lr"), "feature="); ok {
			out[p] = DaemonFeature(feat)
		}
		collectFieldFeatures(f.Type, p, out, seen)
	}
}
