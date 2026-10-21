import fs from 'node:fs';
import path from 'node:path';
import { applyLaunchroomConfig, logConfigResult } from './config.js';
import { finalize } from './finalize.js';
import { buildManifest, MANIFEST_FILE } from './manifest.js';
import { ADAPTER_NAME } from './version.js';
export { applyLaunchroomConfig } from './config.js';
export { finalize, FinalizeError } from './finalize.js';
export { buildManifest, summarize, MANIFEST_FILE, SUMMARY_MARKER } from './manifest.js';
export { VERSION } from './version.js';
/** Creates the adapter; the default export is `createAdapter()` with process.env. */
export function createAdapter(options = {}) {
    const env = options.env ?? process.env;
    const log = options.log ?? ((m) => console.log(m));
    let hooked = false;
    return {
        name: ADAPTER_NAME,
        modifyConfig(config, ctx) {
            const result = applyLaunchroomConfig(config, ctx, env);
            // modifyConfig runs for every command that loads next.config; log once per build.
            if (ctx.phase === 'phase-production-build')
                logConfigResult(result, log);
            return result.config;
        },
        onBuildComplete(ctx) {
            const manifest = buildManifest(ctx);
            fs.mkdirSync(ctx.distDir, { recursive: true });
            fs.writeFileSync(path.join(ctx.distDir, MANIFEST_FILE), JSON.stringify(manifest, null, 2) + '\n');
            for (const w of manifest.warnings)
                log(`[launchroom] warning: ${w}`);
            const wantHook = (options.finalizeOnExit ?? true) && env.LAUNCHROOM_NEXT_FINALIZE !== 'manual';
            if (!manifest.standalone || !wantHook || hooked)
                return;
            hooked = true;
            // Next writes .next/standalone only after this hook returns, so the
            // copy runs when `next build` exits successfully (see finalize.ts).
            process.once('exit', (code) => {
                if (code !== 0)
                    return;
                try {
                    const r = finalize({ projectDir: ctx.projectDir, distDir: ctx.distDir });
                    log(`[launchroom] standalone output ready: ${path.relative(ctx.projectDir, r.serverEntry)} (public: ${r.copiedPublic ? 'copied' : 'none'}, static: ${r.copiedStatic ? 'copied' : 'none'})`);
                }
                catch (err) {
                    log(`[launchroom] error: ${err.message}`);
                    process.exitCode = 1;
                }
            });
        },
    };
}
const adapter = createAdapter();
export default adapter;
