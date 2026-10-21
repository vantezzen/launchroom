#!/usr/bin/env node
// launchroom-next-finalize [projectDir] [--dist-dir <dir>]
//
// Copies public/ and .next/static into the standalone output (idempotent) and
// prints one `launchroom-next-summary {json}` line that launchroomd
// turns into the build summary. Exit code 1 if there is no standalone output.
import { finalize, FinalizeError } from './finalize.js';
import { summarize, SUMMARY_MARKER } from './manifest.js';
function main(argv) {
    let projectDir = process.cwd();
    let distDir;
    for (let i = 0; i < argv.length; i++) {
        const a = argv[i];
        if (a === '--dist-dir') {
            distDir = argv[++i];
        }
        else if (a === '-h' || a === '--help') {
            console.log('usage: launchroom-next-finalize [projectDir] [--dist-dir <dir>]');
            return 0;
        }
        else if (a !== undefined) {
            projectDir = a;
        }
    }
    try {
        const r = finalize(distDir === undefined ? { projectDir } : { projectDir, distDir });
        console.log(`[launchroom] standalone output: ${r.serverEntry} (public: ${r.copiedPublic ? 'copied' : 'none'}, static: ${r.copiedStatic ? 'copied' : 'none'})`);
        if (r.manifest) {
            console.log(SUMMARY_MARKER + JSON.stringify(summarize(r.manifest)));
        }
        else {
            console.log('[launchroom] no launchroom-next-manifest.json (the adapter did not run during next build)');
        }
        return 0;
    }
    catch (err) {
        console.error(`[launchroom] error: ${err instanceof FinalizeError ? err.message : String(err)}`);
        return 1;
    }
}
process.exitCode = main(process.argv.slice(2));
