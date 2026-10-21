import assert from 'node:assert/strict';
import path from 'node:path';
import { describe, it } from 'node:test';

import { buildManifest, summarize, SUMMARY_MARKER } from '../dist/index.js';
import { buildCtx, outputs, sampleOutputs } from './helpers.ts';

describe('buildManifest', () => {
  it('summarizes routes, ISR, middleware and images', () => {
    const m = buildManifest(buildCtx('/app', { outputs: sampleOutputs() }));
    assert.equal(m.schemaVersion, 1);
    assert.equal(m.adapter.name, 'launchroom');
    assert.equal(m.deploymentId, 'dpl_01j9z8x7w6v5t4s3r2q1p0n9n5');
    assert.deepEqual(m.standalone, { appDir: '.next/standalone', serverEntry: '.next/standalone/server.js' });
    assert.deepEqual(
      m.routes.list.map((r) => `${r.kind} ${r.pathname} ${r.runtime}`),
      ['app-page / nodejs', 'app-route /api/health nodejs', 'app-page /edgy edge', 'app-route /favicon.ico nodejs', 'app-page /isr nodejs'],
    );
    assert.equal(m.routes.appPages, 3);
    assert.equal(m.routes.appRoutes, 2);
    assert.equal(m.routes.edge, 1);
    assert.equal(m.routes.staticFiles, 1);
    assert.deepEqual(m.prerenders, { static: 1, isr: [{ pathname: '/isr', route: '/isr', revalidate: 60 }] });
    assert.deepEqual(m.middleware, { present: true, runtime: 'nodejs', matchers: ['/((?!_next/static).*)'] });
    assert.equal(m.imageOptimization.enabled, true);
    assert.equal(m.imageOptimization.sharp, false);
    assert.ok(m.warnings.some((w) => w.includes('edge runtime')));
    assert.ok(m.warnings.some((w) => w.includes('ISR')));
  });

  it('handles a monorepo tracing root and disabled image optimization', () => {
    const ctx = buildCtx('/repo/apps/web', {
      distDir: '/repo/apps/web/.next',
      config: { output: 'standalone', outputFileTracingRoot: '/repo', images: { unoptimized: true }, cacheHandler: '/repo/cache.js' },
      outputs: outputs(),
    });
    const m = buildManifest(ctx);
    assert.deepEqual(m.standalone, { appDir: '.next/standalone/apps/web', serverEntry: '.next/standalone/apps/web/server.js' });
    assert.equal(m.imageOptimization.enabled, false);
    assert.equal(m.cacheHandler, true);
    assert.equal(m.deploymentId, null);
    assert.equal(m.middleware.present, false);
  });

  it('has no standalone paths without standalone output', () => {
    const m = buildManifest(buildCtx('/app', { config: {} }));
    assert.equal(m.standalone, null);
    assert.ok(m.warnings.some((w) => w.includes("not 'standalone'")));
  });

  it('produces a bounded one-line summary', () => {
    const many = Array.from({ length: 100 }, (_, i) => ({ id: `/p/${i}`, pathname: `/p/${i}`, fallback: { initialRevalidate: 10 } }));
    const s = summarize(buildManifest(buildCtx(path.sep + 'app', { outputs: outputs({ prerenders: many }) })));
    assert.equal(s.routes.isr, 100);
    assert.equal(s.isrRoutes.length, 32);
    const line = SUMMARY_MARKER + JSON.stringify(s);
    assert.ok(!line.includes('\n'));
    assert.ok(line.length < 8192, `summary line is ${line.length} bytes`);
  });
});
