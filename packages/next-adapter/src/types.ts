// Minimal structural types for the parts of the Next.js Deployment Adapters
// API (`import type { NextAdapter } from 'next'`) this adapter uses. They are
// declared here so the package has no dependency on `next` (not even for
// types) and stays compatible across versions: Next passes the complete
// objects, we only read what is listed. Next 15.4 and 15.5 call modifyConfig
// without a context and pass onBuildComplete only routes and a flat output
// list; 16.0 to 16.2 pass modifyConfig only the phase (and nextVersion from
// 16.2), 16.3 adds projectDir.

export type Phase =
  | 'phase-export'
  | 'phase-production-build'
  | 'phase-production-server'
  | 'phase-development-server'
  | 'phase-test'
  | 'phase-info'
  | (string & {});

/** The subset of `NextConfigComplete` the adapter reads or writes. */
export interface NextConfigLike {
  output?: 'standalone' | 'export' | undefined;
  deploymentId?: string | undefined;
  distDir?: string;
  basePath?: string;
  trailingSlash?: boolean;
  outputFileTracingRoot?: string;
  turbopack?: { root?: string; [key: string]: unknown };
  cacheHandler?: string | undefined;
  cacheHandlers?: Record<string, string | undefined> | undefined;
  images?: {
    unoptimized?: boolean;
    loader?: string;
    loaderFile?: string;
    remotePatterns?: unknown[];
    domains?: string[];
    [key: string]: unknown;
  };
  [key: string]: unknown;
}

export interface ModifyConfigContext {
  phase: Phase;
  nextVersion: string;
  projectDir: string;
}

type Runtime = 'nodejs' | 'edge';

interface RouteOutput {
  id: string;
  filePath: string;
  pathname: string;
  sourcePage?: string;
  runtime: Runtime;
}

export interface PrerenderOutput {
  id: string;
  pathname: string;
  parentOutputId?: string;
  route?: string;
  routeType?: 'route' | 'fallback' | 'shell' | 'page';
  fallback?: {
    filePath?: string | undefined;
    initialRevalidate?: number | false;
    initialExpiration?: number;
  };
  config?: { renderingMode?: 'STATIC' | 'PARTIALLY_STATIC'; [key: string]: unknown };
}

export interface MiddlewareOutput extends RouteOutput {
  config?: { matchers?: Array<{ source?: string; sourceRegex?: string }>; [key: string]: unknown };
}

export interface AdapterOutputs {
  pages: RouteOutput[];
  pagesApi: RouteOutput[];
  appPages: RouteOutput[];
  appRoutes: RouteOutput[];
  prerenders: PrerenderOutput[];
  staticFiles: Array<{ id: string; filePath: string; pathname: string }>;
  middleware?: MiddlewareOutput | undefined;
}

/**
 * One output as Next 15.4 and 15.5 pass them: a flat list, each entry with
 * its `type` (PAGES, PAGES_API, APP_PAGE, APP_ROUTE, PRERENDER, STATIC_FILE,
 * MIDDLEWARE). Next 16 groups them into {@link AdapterOutputs}.
 */
export type FlatAdapterOutput = { type?: string; id: string; pathname: string; [key: string]: unknown };

export interface BuildCompleteContext {
  routing?: unknown;
  outputs: AdapterOutputs;
  projectDir: string;
  repoRoot: string;
  distDir: string;
  config: NextConfigLike;
  nextVersion: string;
  buildId: string;
}

/** What onBuildComplete receives across Next versions (see completeBuildContext). */
export type GivenBuildCompleteContext = Partial<Omit<BuildCompleteContext, 'outputs'>> & {
  /** Up to 16.1; 16.2 renamed it to `routing`. Not read. */
  routes?: unknown;
  outputs: AdapterOutputs | FlatAdapterOutput[];
};

export interface NextAdapter {
  name: string;
  /** `ctx` is missing on Next 15.4 and 15.5; Next 16 passes only `phase`. */
  modifyConfig?: (config: NextConfigLike, ctx?: Partial<ModifyConfigContext>) => Promise<NextConfigLike> | NextConfigLike;
  /** Next 15.4 and 15.5 pass only `routes` and `outputs`, and the outputs as a flat list. */
  onBuildComplete?: (ctx: GivenBuildCompleteContext) => Promise<void> | void;
}

/** Environment variables the adapter reads (all optional). */
export type Env = Record<string, string | undefined>;

export type Logger = (message: string) => void;
