import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import type { AdapterOutputs, BuildCompleteContext } from '../dist/index.js';

export function tmpProject(): string {
  return fs.mkdtempSync(path.join(os.tmpdir(), 'lr-next-adapter-'));
}

export function write(file: string, content = ''): void {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, content);
}

/** Lays out what `next build` leaves behind with output: 'standalone'. */
export function fakeStandaloneBuild(projectDir: string, relativeAppDir = ''): void {
  const dist = path.join(projectDir, '.next');
  write(path.join(dist, 'required-server-files.json'), JSON.stringify({ version: 1, relativeAppDir }));
  write(path.join(dist, 'standalone', relativeAppDir, 'server.js'), '// server');
  write(path.join(dist, 'static', 'chunks', 'main-abc123.js'), 'chunk');
  write(path.join(projectDir, 'public', 'robots.txt'), 'User-agent: *');
}

export function outputs(partial: Partial<AdapterOutputs> = {}): AdapterOutputs {
  return { pages: [], pagesApi: [], appPages: [], appRoutes: [], prerenders: [], staticFiles: [], ...partial };
}

export function buildCtx(projectDir: string, partial: Partial<BuildCompleteContext> = {}): BuildCompleteContext {
  return {
    outputs: outputs(),
    projectDir,
    repoRoot: projectDir,
    distDir: path.join(projectDir, '.next'),
    config: { output: 'standalone', outputFileTracingRoot: projectDir, deploymentId: 'dpl_01j9z8x7w6v5t4s3r2q1p0n9n5', images: {} },
    nextVersion: '16.4.2',
    buildId: 'build-1',
    ...partial,
  };
}

const route = (type: string, pathname: string, runtime: 'nodejs' | 'edge' = 'nodejs') => ({
  type,
  id: pathname,
  pathname,
  filePath: '/x/' + pathname,
  sourcePage: pathname,
  runtime,
});

/** Outputs shaped like Next 16.4's for a small App Router app. */
export function sampleOutputs(): AdapterOutputs {
  return outputs({
    appPages: [
      route('APP_PAGE', '/'),
      route('APP_PAGE', '/index.rsc'),
      route('APP_PAGE', '/isr'),
      route('APP_PAGE', '/isr.rsc'),
      route('APP_PAGE', '/_not-found'),
      route('APP_PAGE', '/edgy', 'edge'),
    ],
    appRoutes: [route('APP_ROUTE', '/api/health'), route('APP_ROUTE', '/favicon.ico')],
    prerenders: [
      { id: '/', pathname: '/', route: '/', fallback: { initialRevalidate: false } },
      { id: '/index.rsc', pathname: '/index.rsc', route: '/', fallback: { initialRevalidate: false } },
      { id: '/isr', pathname: '/isr', route: '/isr', fallback: { initialRevalidate: 60 } },
      { id: '/isr.rsc', pathname: '/isr.rsc', route: '/isr', fallback: { initialRevalidate: 60 } },
      { id: '/_not-found', pathname: '/_not-found', fallback: { initialRevalidate: false } },
    ],
    staticFiles: [{ id: 'a', filePath: '/x/a.js', pathname: '/_next/static/chunks/a.js' }],
    middleware: {
      ...route('MIDDLEWARE', '/_middleware'),
      config: { matchers: [{ source: '/((?!_next/static).*)', sourceRegex: '^/.*$' }] },
    },
  });
}
