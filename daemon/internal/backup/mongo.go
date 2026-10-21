package backup

import (
	"context"
	"fmt"
	"io"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// mongoCheckScript counts the collections and documents of the restored
// "verify" database and runs validate on every collection. It is a fixed
// string; it prints "<collections>|<documents>|<invalid collections>".
const mongoCheckScript = `const d = db.getSiblingDB("verify"); let n = 0, docs = 0; const bad = [];
for (const c of d.getCollectionInfos({type: "collection"}, {nameOnly: true})) {
  if (c.name.startsWith("system.")) continue;
  n++; docs += d.getCollection(c.name).countDocuments({});
  const r = d.runCommand({validate: c.name});
  if (r.ok !== 1 || r.valid !== true) bad.push(c.name);
}
print(n + "|" + docs + "|" + bad.join(","));`

// verifyMongo restores the archive into a throwaway mongod (no network, no
// users: nothing but launchroomd's exec can reach it) as database
// "verify", counts it and validates every collection.
func (e *Engine) verifyMongo(ctx context.Context, r *repo, spec *state.VerifyRestoreSpec, snap resticSnapshot, vol string, v *verifier, logf Logf) error {
	id, err := e.createThrowaway(ctx, throwaway{purpose: helperVerify, image: spec.Image, volume: vol, mountPath: spec.DataPath, resources: spec.Resources})
	if err != nil {
		return err
	}
	defer e.removeQuietly(ctx, id)
	logf("Starting a throwaway mongodb to restore snapshot %s into", short(snap.ID))
	if err := e.o.Runtime.StartContainer(ctx, id); err != nil {
		return fmt.Errorf("backup: starting the throwaway database: %w", err)
	}
	if !v.check(state.VerifyCheckStart, e.waitFor(ctx, id, nil, nil, "mongosh", "--quiet", "--norc", "mongodb://127.0.0.1:27017/verify", "--eval", "db.runCommand({ping: 1}).ok"), "a fresh mongodb started") {
		return nil
	}
	argv := []string{"mongorestore", "--host=127.0.0.1", "--port=27017", "--quiet", "--archive", "--gzip", "--nsFrom=$db$.$coll$", "--nsTo=verify.$coll$", "--stopOnError"}
	var restored int64
	err = r.streamFile(ctx, snap.ID, mongo.file, func(src io.Reader) error {
		counted := &countingReader{r: src}
		res, err := e.o.Runtime.ExecStream(ctx, id, docker.ExecOptions{Argv: argv, Stdin: counted})
		restored = counted.n
		switch {
		case err != nil:
			return err
		case res.ExitCode != 0:
			return fmt.Errorf("mongorestore exited with code %d: %s", res.ExitCode, firstLines(res.Stderr))
		}
		return nil
	})
	v.res.Bytes = restored
	if !v.check(state.VerifyCheckRestore, err, humanBytes(restored)+" restored") {
		return nil
	}
	logf("Restored %s; checking it", humanBytes(restored))
	out, res, err := e.execIn(ctx, id, nil, nil, "mongosh", "--quiet", "--norc", "mongodb://127.0.0.1:27017/verify", "--eval", mongoCheckScript)
	if err == nil && res.ExitCode != 0 {
		err = fmt.Errorf("mongosh exited with code %d: %s", res.ExitCode, firstLines(res.Stderr))
	}
	var invalid string
	if err == nil {
		f := strings.SplitN(lastLine(out), "|", 3)
		if len(f) != 3 {
			err = fmt.Errorf("unexpected check output %q", clip(out, 64))
		} else {
			invalid = f[2]
			var collections, docs int64
			collections, docs, err = parseCounts(f[0] + "|" + f[1])
			v.res.Tables, v.res.Rows = collections, docs
		}
	}
	v.check(state.VerifyCheckQuery, err, fmt.Sprintf("%d collections, %d documents", v.res.Tables, v.res.Rows))
	var verr error
	if err != nil {
		verr = err
	} else if invalid != "" {
		verr = fmt.Errorf("validate reported problems in %s", clip(invalid, 200))
	}
	v.check(state.VerifyCheckValidate, verr, "validate: every collection OK")
	return nil
}
