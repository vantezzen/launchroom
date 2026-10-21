// Package integration runs the daemon's reconciler, health probes and Caddy
// ingress against a real Docker daemon (build tag "integration"). The tests
// must run INSIDE the dedicated ephemeral dind-test container
// (`make test-daemon-integration`), sharing the daemon's network and PID
// namespaces like the daemon on a real server. Every object in that daemon
// belongs to these tests; they never touch the host Docker daemon.
package integration
