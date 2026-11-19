import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

import { applyLaunchroomConfig, logConfigResult } from './config.js';
import { finalize } from './finalize.js';
import { buildManifest, MANIFEST_FILE } from './manifest.js';
import type {
  AdapterOutputs,
  BuildCompleteContext,
  Env,
  FlatAdapterOutput,
  GivenBuildCompleteContext,
  Logger,
  ModifyConfigContext,
  NextAdapter,
  NextConfigLike,
  PrerenderOutput,
} from './types.js';
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

/**
 * The context Next 15.4 and 15.5 don't pass to modifyConfig: `next build`
 * sets NEXT_PHASE and runs in the project directory. Without NEXT_PHASE the
 * phase counts as a production build, since launchroom sets
 * NEXT_ADAPTER_PATH for builds only.
 */
export function contextFromProcess(env: Env, cwd: string = process.cwd()): ModifyConfigContext {
  return { phase: env.NEXT_PHASE?.trim() || 'phase-production-build', nextVersion: '', projectDir: cwd };
}

/**
 * modifyConfig's context with what this Next version left out filled in:
 * Next 15.4 and 15.5 pass none, 16.0 to 16.2 no projectDir (README).
 */
export function completeConfigContext(ctx: Partial<ModifyConfigContext> | undefined, env: Env, cwd: string = process.cwd()): ModifyConfigContext {
  const fallback = contextFromProcess(env, cwd);
  return {
    phase: ctx?.phase || fallback.phase,
    nextVersion: ctx?.nextVersion || fallback.nextVersion,
    projectDir: ctx?.projectDir || fallback.projectDir,
  };
}

/**
 * onBuildComplete's context with what this Next version left out filled in.
 * Next 15.4 and 15.5 pass only routes and outputs: the config is the one
 * modifyConfig returned (Next builds with it), the project is the working
 * directory of `next build`, the build id is the dist dir's BUILD_ID, and
 * the flat output list is grouped like Next 16's.
 */
export function completeBuildContext(ctx: GivenBuildCompleteContext, config: NextConfigLike | undefined, cwd: string = process.cwd()): BuildCompleteContext {
  const projectDir = ctx.projectDir || cwd;
  const resolved = ctx.config ?? config ?? {};
  const distDir = ctx.distDir || path.resolve(projectDir, typeof resolved.distDir === 'string' && resolved.distDir ? resolved.distDir : '.next');
  return {
    ...ctx,
    outputs: Array.isArray(ctx.outputs) ? groupOutputs(ctx.outputs, distDir) : ctx.outputs,
    projectDir,
    repoRoot: ctx.repoRoot || (typeof resolved.outputFileTracingRoot === 'string' && resolved.outputFileTracingRoot) || projectDir,
    distDir,
    config: resolved,
    nextVersion: ctx.nextVersion || nextVersionOf(projectDir),
    buildId: ctx.buildId || readTrimmed(path.join(distDir, 'BUILD_ID')),
  };
}

/**
 * Groups Next 15's flat output list by type. Next 15.5 reports every
 * prerender with a revalidate of at least 1 (`initialRevalidate || 1`), so
 * the revalidate comes from prerender-manifest.json instead: false (static)
 * unless the manifest has a number of seconds.
 */
export function groupOutputs(list: FlatAdapterOutput[], distDir: string): AdapterOutputs {
  const grouped: AdapterOutputs = { pages: [], pagesApi: [], appPages: [], appRoutes: [], prerenders: [], staticFiles: [] };
  const manifest = readJSON<{
    routes?: Record<string, { initialRevalidateSeconds?: unknown }>;
    dynamicRoutes?: Record<string, { fallbackRevalidate?: unknown }>;
  }>(path.join(distDir, 'prerender-manifest.json'));
  const revalidateOf = (pathname: string): number | false => {
    const r = manifest?.routes?.[pathname]?.initialRevalidateSeconds ?? manifest?.dynamicRoutes?.[pathname]?.fallbackRevalidate;
    return typeof r === 'number' && r > 0 ? r : false;
  };
  for (const o of list) {
    const out = o as never;
    switch (o.type) {
      case 'PAGES':
        grouped.pages.push(out);
        break;
      case 'PAGES_API':
        grouped.pagesApi.push(out);
        break;
      case 'APP_PAGE':
        grouped.appPages.push(out);
        break;
      case 'APP_ROUTE':
        grouped.appRoutes.push(out);
        break;
      case 'STATIC_FILE':
        grouped.staticFiles.push(out);
        break;
      case 'MIDDLEWARE':
        grouped.middleware = out;
        break;
      case 'PRERENDER': {
        const p = o as unknown as PrerenderOutput;
        grouped.prerenders.push({ ...p, fallback: { ...p.fallback, initialRevalidate: revalidateOf(p.pathname) } });
        break;
      }
    }
  }
  return grouped;
}

function readJSON<T>(file: string): T | null {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8')) as T;
  } catch {
    return null;
  }
}

function readTrimmed(file: string): string {
  try {
    return fs.readFileSync(file, 'utf8').trim();
  } catch {
    return '';
  }
}

/** The installed Next's version, looked up from the project like Node would. */
function nextVersionOf(projectDir: string): string {
  try {
    const pkg = createRequire(path.join(projectDir, 'package.json'))('next/package.json') as { version?: unknown };
    return typeof pkg.version === 'string' ? pkg.version : '';
  } catch {
    return '';
  }
}

/** Creates the adapter; the default export is `createAdapter()` with process.env. */
export function createAdapter(options: AdapterOptions = {}): NextAdapter {
  const env = options.env ?? process.env;
  const log = options.log ?? ((m: string) => console.log(m));
  let hooked = false;
  let logged = false;
  // What modifyConfig returned, for Next versions that don't pass the config to onBuildComplete.
  let modified: NextConfigLike | undefined;

  return {
    name: ADAPTER_NAME,

    modifyConfig(config: NextConfigLike, ctx?: Partial<ModifyConfigContext>): NextConfigLike {
      const context = completeConfigContext(ctx, env);
      const result = applyLaunchroomConfig(config, context, env);
      modified = result.config;
      // modifyConfig runs for every command that loads next.config; log once per build.
      if (context.phase === 'phase-production-build' && !logged) {
        logged = true;
        logConfigResult(result, log);
      }
      return result.config;
    },

    onBuildComplete(given: GivenBuildCompleteContext): void {
      const ctx = completeBuildContext(given, modified);
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
