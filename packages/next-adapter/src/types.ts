// Minimal structural types for the parts of the Next.js Deployment Adapters
// API (next >= 16.2, `import type { NextAdapter } from 'next'`) this adapter
// uses. They are declared here so the package has no dependency on `next`
// (not even for types) and stays compatible across 16.x minors: Next passes
// the complete objects, we only read what is listed.

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

export interface NextAdapter {
  name: string;
  modifyConfig?: (config: NextConfigLike, ctx: ModifyConfigContext) => Promise<NextConfigLike> | NextConfigLike;
  onBuildComplete?: (ctx: BuildCompleteContext) => Promise<void> | void;
}

/** Environment variables the adapter reads (all optional). */
export type Env = Record<string, string | undefined>;

export type Logger = (message: string) => void;
