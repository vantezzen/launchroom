package main

import (
	"errors"
	"fmt"
	"path/filepath"

	"github.com/vantezzen/launchroom/daemon/internal/backup"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
	"github.com/vantezzen/launchroom/daemon/internal/transfer"
)

// setupBackups prepares the backup engine (docs/14) and the WAL shipper of
// point-in-time recovery (§10) when the Docker client can exec into
// containers. A missing restic binary is not fatal: tasks and shipping then
// fail with a clear message.
func (rt *daemonRuntime) setupBackups() {
	ex, ok := rt.docker.(docker.Execer)
	if !ok {
		return
	}
	bin := backup.LookupBinary(rt.cfg.ResticBinary)
	if bin == "" {
		rt.log.Warn("restic not found; backup tasks will fail until it is installed", "env", "LR_RESTIC_BINARY")
	}
	o := backup.Options{
		ResticBinary: bin, DataDir: rt.cfg.DataDir, InsecureHosts: rt.cfg.BackupInsecureHosts,
		Docker:      backupDocker{Client: rt.docker, Execer: ex},
		WorkloadEnv: rt.workloadEnv,
		ServerID:    string(rt.serverID),
		Policy:      rt.policy,
		Workload:    rt.workloadRuntime,
	}
	if rt.client != nil {
		o.Transfers = rt.client
	}
	if rt.firewall != nil {
		o.Isolate = rt.firewall.Sync
	}
	transfer.Sweep(filepath.Join(rt.cfg.DataDir, "transfers"), rt.now())
	vo, volumes := rt.docker.(docker.VolumeOps)
	if volumes {
		o.Volumes = volumeDocker{Client: rt.docker, VolumeOps: vo}
		o.Runtime = runtimeDocker{Client: rt.docker, Execer: ex, VolumeOps: vo}
	}
	rt.backupEngine = backup.New(o)
	if volumes {
		rt.walArchive = backup.NewWalArchiver(backup.WalArchiverOptions{
			Engine: rt.backupEngine, Docker: walDocker{Client: rt.docker, Execer: ex, VolumeOps: vo},
			Credentials: rt.walCredentials, OnChange: rt.kickReport, Log: rt.log.With("component", "walarchive"), Now: rt.now,
		})
	}
}

// addBackupHandlers registers the backup task types (docs/14).
func (rt *daemonRuntime) addBackupHandlers(h map[state.TaskType]tasks.Handler) {
	if rt.backupEngine == nil {
		return
	}
	// One heavy-work slot per server, shared by backups, restores, imports and exports.
	slots := make(chan struct{}, 1)
	bh := tasks.BackupHandler(tasks.BackupOptions{
		ServerID: rt.serverID, SealingKey: rt.sealingKey, Engine: rt.backupEngine, Client: rt.client, Slots: slots,
	})
	for _, t := range []state.TaskType{state.TaskBackup, state.TaskRestore, state.TaskBackupSnapshots, state.TaskBackupMaintenance, state.TaskVerifyRestore} {
		h[t] = bh
	}
	if rt.dataTransfers() {
		th := tasks.TransferHandler(tasks.TransferOptions{
			ServerID: rt.serverID, SealingKey: rt.sealingKey, Engine: rt.backupEngine, Client: rt.client, Slots: slots,
		})
		for _, t := range []state.TaskType{state.TaskDbImport, state.TaskVolumeImport, state.TaskDbExport, state.TaskVolumeExport} {
			h[t] = th
		}
	}
}

// dataTransfers reports whether imports and exports run here (D55): the
// local LR_DATA_TRANSFERS allows them and Docker can run volume helpers and
// throwaways.
func (rt *daemonRuntime) dataTransfers() bool {
	if !rt.cfg.DataTransfers || rt.backupEngine == nil {
		return false
	}
	_, volumes := rt.docker.(docker.VolumeOps)
	return volumes
}

// workloadRuntime returns the image and environment network of a workload in
// the verified desired state (imports run their throwaway there).
func (rt *daemonRuntime) workloadRuntime(id state.WorkloadID) (backup.WorkloadRuntime, error) {
	cur := rt.store.Current()
	if cur == nil {
		return backup.WorkloadRuntime{}, errors.New("no desired state yet")
	}
	for i := range cur.State.Workloads {
		w := &cur.State.Workloads[i]
		if w.ID != id {
			continue
		}
		for _, a := range w.Networks {
			for _, n := range cur.State.Networks {
				if n.ID == a.Network {
					return backup.WorkloadRuntime{Image: w.Image, Network: string(n.Name)}, nil
				}
			}
		}
		return backup.WorkloadRuntime{}, fmt.Errorf("workload %s has no environment network", id)
	}
	return backup.WorkloadRuntime{}, fmt.Errorf("workload %s is not in this server's desired state", id)
}

// walArchiveStatus is Report.walArchives (nil without shippers).
func (rt *daemonRuntime) walArchiveStatus() []state.WalArchiveStatus {
	if rt.walArchive == nil {
		return nil
	}
	if st := rt.walArchive.Status(); len(st) > 0 {
		return st
	}
	return nil
}

// walCredentials opens a walArchive's destination credentials: a
// WorkloadEnv sealed to this server and the workload (docs/14 §10.2).
func (rt *daemonRuntime) walCredentials(wl state.WorkloadID, spec *state.WalArchiveSpec) (backup.Credentials, error) {
	env, err := sealed.OpenWorkloadEnv(spec.Destination.Credentials, rt.sealingKey, rt.serverID, wl)
	if err != nil {
		return backup.Credentials{}, fmt.Errorf("wal archive credentials: %w", err)
	}
	return backup.CredentialsFromEnv(spec.Destination.Kind, env)
}

type backupDocker struct {
	docker.Client
	docker.Execer
}

type volumeDocker struct {
	docker.Client
	docker.VolumeOps
}

type runtimeDocker struct {
	docker.Client
	docker.Execer
	docker.VolumeOps
}

type walDocker struct {
	docker.Client
	docker.Execer
	docker.VolumeOps
}

// workloadEnv opens the sealed env of a workload in the verified desired state.
func (rt *daemonRuntime) workloadEnv(id state.WorkloadID) (map[string]secret.Secret, error) {
	cur := rt.store.Current()
	if cur == nil {
		return nil, errors.New("no desired state yet")
	}
	for i := range cur.State.Workloads {
		if w := &cur.State.Workloads[i]; w.ID == id {
			return sealed.OpenWorkloadEnv(w.Env, rt.sealingKey, rt.serverID, w.ID)
		}
	}
	return nil, fmt.Errorf("workload %s is not in this server's desired state", id)
}
