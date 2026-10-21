// Refreshes the files launchroom.vantezzen.io serves verbatim from the repo
// (docs/21 §3). Runs as `predev`/`prebuild`, so every host runs it:
//   /install                       deploy/install/install.sh (curl … | sh)
//   /schema/launchroom.v1.json     the launchroom.json JSON Schema ($id, D45)
//   /schema/v1/*.json              protocol JSON Schemas ($id prefix, protocol/)
// The copies in public/ are committed, so a host that only has the site/
// folder still serves them; with the whole repo checked out they are refreshed
// from the source of truth first (CI fails when the committed copies are stale).
import { copyFileSync, existsSync, mkdirSync, readdirSync, rmSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const site = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const repo = resolve(site, '..');
const pub = join(site, 'public');

const sources = ['deploy/install/install.sh', 'dashboard/resources/schema/launchroom.v1.json', 'protocol/schema/v1'];
const missing = sources.filter((s) => !existsSync(join(repo, s)));
if (missing.length > 0) {
    console.log(
        `sync-public: skipped (not in the launchroom repo: ${missing.join(', ')} missing); serving the committed copies in public/`,
    );
    process.exit(0);
}

function copy(from, to) {
    mkdirSync(dirname(join(pub, to)), { recursive: true });
    copyFileSync(join(repo, from), join(pub, to));
}

rmSync(join(pub, 'schema'), { recursive: true, force: true });
copy('deploy/install/install.sh', 'install');
copy('dashboard/resources/schema/launchroom.v1.json', 'schema/launchroom.v1.json');
for (const file of readdirSync(join(repo, 'protocol/schema/v1')).filter((f) => f.endsWith('.json'))) {
    copy(`protocol/schema/v1/${file}`, `schema/v1/${file}`);
}
console.log('sync-public: install script and JSON Schemas copied from the repo into public/');
