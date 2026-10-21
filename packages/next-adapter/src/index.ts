import fs from 'node:fs';
import path from 'node:path';

import { applyLaunchroomConfig, logConfigResult } from './config.js';
import { finalize } from './finalize.js';
import { buildManifest, MANIFEST_FILE } from './manifest.js';
import type { BuildCompleteContext, Env, Logger, ModifyConfigContext, NextAdapter, NextConfigLike } from './types.js';
import { ADAPTER_NAME } from './version.js';

export { applyLaunchroomConfig } from './config.js';
export { finalize, FinalizeError } from './finalize.js';
export { buildManifest, summarize, MANIFEST_FILE, SUMMARY_MARKER } from './manifest.js';
export type { LaunchroomNextManifest, LaunchroomNextSummary } from './manifest.js';
export type * from './types.js';
export { VERSION } from './version.js';

export interface AdapterOptions {
  env?: Env;
  log?: Logger;
  /** Register the process exit hook that finalizes the standalone output (default true). */
  finalizeOnExit?: boolean;
}

/** Creates the adapter; the default export is `createAdapter()` with process.env. */
export function createAdapter(options: AdapterOptions = {}): NextAdapter {
  const env = options.env ?? process.env;
  const log = options.log ?? ((m: string) => console.log(m));
  let hooked = false;

  return {
    name: ADAPTER_NAME,

    modifyConfig(config: NextConfigLike, ctx: ModifyConfigContext): NextConfigLike {
      const result = applyLaunchroomConfig(config, ctx, env);
      // modifyConfig runs for every command that loads next.config; log once per build.
      if (ctx.phase === 'phase-production-build') logConfigResult(result, log);
      return result.config;
    },

    onBuildComplete(ctx: BuildCompleteContext): void {
      const manifest = buildManifest(ctx);
      fs.mkdirSync(ctx.distDir, { recursive: true });
      fs.writeFileSync(path.join(ctx.distDir, MANIFEST_FILE), JSON.stringify(manifest, null, 2) + '\n');
      for (const w of manifest.warnings) log(`[launchroom] warning: ${w}`);

      const wantHook = (options.finalizeOnExit ?? true) && env.LAUNCHROOM_NEXT_FINALIZE !== 'manual';
      if (!manifest.standalone || !wantHook || hooked) return;
      hooked = true;
      // Next writes .next/standalone only after this hook returns, so the
      // copy runs when `next build` exits successfully (see finalize.ts).
      process.once('exit', (code) => {
        if (code !== 0) return;
        try {
          const r = finalize({ projectDir: ctx.projectDir, distDir: ctx.distDir });
          log(`[launchroom] standalone output ready: ${path.relative(ctx.projectDir, r.serverEntry)} (public: ${r.copiedPublic ? 'copied' : 'none'}, static: ${r.copiedStatic ? 'copied' : 'none'})`);
        } catch (err) {
          log(`[launchroom] error: ${(err as Error).message}`);
          process.exitCode = 1;
        }
      });
    },
  };
}

const adapter: NextAdapter = createAdapter();
export default adapter;
