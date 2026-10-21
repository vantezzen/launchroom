// Package update implements launchroomd's signed self-update and the
// control-plane update (docs/11 §4, docs/09 R30, docs/03 §7 self_update).
//
// Trust: the installer pins the release identity in data/trust.json
// (keyless cosign: Fulcio certificate for the GitHub Actions workflow
// vantezzen/launchroom/.github/workflows/release.yml@refs/tags/v<version>,
// issuer token.actions.githubusercontent.com). The dashboard only names a
// version; launchroomd downloads that release's deploy bundle itself from
// the pinned release base, verifies its sigstore bundle with sigstore-go
// (Sigstore trusted root refreshed through TUF, embedded root as offline
// fallback), refuses anything that is not newer than what runs, and takes
// every image digest from the verified bundle.
//
// Swap: launchroomd runs in a compose-managed container and cannot replace
// itself in place. The task handler verifies and stages everything, then
// starts a short-lived, labelled supervisor container from launchroomd's
// CURRENT (known good) image ("launchroomd update-supervisor <plan>").
// The supervisor backs up the managed files (and, for the control plane,
// dumps the database), runs the verified bundle's installer (install.sh /
// server.sh, the documented upgrade path) in a helper container of the
// digest-pinned docker CLI image from the bundle (LR_COMPOSE_IMAGE), and
// waits until the new launchroomd reports itself running (data/update/
// running.json, written after its first signed exchange with the control
// plane). If that does not happen in time it restores the files (and the
// database) and runs compose again, so the previous version comes back.
// It records the outcome in data/update/tasks/<task>/status.json; the
// launchroomd process that runs afterwards (new or old) reports it to the
// dashboard.
package update
