package state

// ProtocolDocument is one top-level protocol document: what crosses the wire or is
// sealed, with the schema file schemagen writes for it.
type ProtocolDocument struct {
	File  string
	Kind  string // "" for documents without TypeMeta
	Value any
	Title string
}

// Documents lists every protocol document of v1; schemagen generates one
// schema per entry and protocol/schema/v1/vocabulary.json from all of them.
func Documents() []ProtocolDocument {
	return []ProtocolDocument{
		{"node-state.json", KindNodeState, &NodeState{}, "NodeState: signed desired state of one server"},
		{"task.json", "", &Task{}, "Task: a leased unit of work with a typed spec"},
		{"lease-request.json", KindLeaseRequest, &LeaseRequest{}, "LeaseRequest: POST /daemon/v1/tasks/lease body"},
		{"task-lease.json", KindTaskLease, &TaskLease{}, "TaskLease: signed response to a lease request"},
		{"task-heartbeat.json", KindHeartbeat, &TaskHeartbeat{}, "TaskHeartbeat: POST /daemon/v1/tasks/{id}/heartbeat body"},
		{"task-heartbeat-result.json", KindHeartbeatResult, &TaskHeartbeatResult{}, "TaskHeartbeatResult: signed response to a heartbeat"},
		{"task-completion.json", KindTaskCompletion, &TaskCompletion{}, "TaskCompletion: POST /daemon/v1/tasks/{id}/complete body"},
		{"enroll-request.json", KindEnrollRequest, &EnrollRequest{}, "EnrollRequest: POST /daemon/v1/enroll body"},
		{"enroll-response.json", KindEnrollResponse, &EnrollResponse{}, "EnrollResponse: signed response to an enrollment"},
		{"report.json", KindReport, &Report{}, "Report: POST /daemon/v1/reports body"},
		{"log-batch.json", KindLogBatch, &LogBatch{}, "LogBatch: POST /daemon/v1/logs body"},
		{"workload-env.json", "", &WorkloadEnv{}, "WorkloadEnv: plaintext sealed into NodeState workloads[].env.sealed (crypto_box_seal)"},
		{"registry-token-request.json", KindRegistryTokenRequest, &RegistryTokenRequest{}, "RegistryTokenRequest: POST /daemon/v1/registry-token body"},
		{"registry-token.json", KindRegistryToken, &RegistryToken{}, "RegistryToken: signed response with a short-lived registry bearer token"},
		{"task-token.json", "", &TaskToken{}, "TaskToken: plaintext sealed into task credentials such as build cloneToken (crypto_box_seal)"},
		{"task-env.json", "", &TaskEnv{}, "TaskEnv: plaintext sealed into build/plan task env (crypto_box_seal)"},
		{"mesh-auth-key.json", "", &MeshAuthKey{}, "MeshAuthKey: plaintext sealed into NodeState system.tailscale.authKey (crypto_box_seal)"},
		{"ingress-secret.json", "", &IngressSecret{}, "IngressSecret: plaintext sealed into NodeState ingress credentials (crypto_box_seal)"},
		{"daemon-config.json", KindDaemonConfig, &DaemonConfig{}, "DaemonConfig: signed response to GET /daemon/v1/config"},
		{"broadcast-auth-request.json", KindBroadcastAuthRequest, &BroadcastAuthRequest{}, "BroadcastAuthRequest: POST /daemon/v1/broadcasting/auth body"},
		{"broadcast-auth.json", KindBroadcastAuth, &BroadcastAuth{}, "BroadcastAuth: signed Pusher channel authorization for the daemon's private channel"},
		{"client-certificate-request.json", KindClientCertificateRequest, &ClientCertificateRequest{}, "ClientCertificateRequest: POST /daemon/v1/client-certificate body (mTLS, D50)"},
		{"client-certificate.json", KindClientCertificate, &ClientCertificate{}, "ClientCertificate: signed response with the server's client certificate (mTLS, D50)"},
		{"session-command.json", KindSessionCommand, &SessionCommand{}, "SessionCommand: signed dashboard command on a session channel (Pusher server event payload)"},
	}
}
