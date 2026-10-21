import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { applyLaunchroomConfig } from '../dist/index.js';

const build = { phase: 'phase-production-build', nextVersion: '16.4.2', projectDir: '/app' } as const;
const ids = (xs: Array<{ id: string }>) => xs.map((x) => x.id);

describe('applyLaunchroomConfig', () => {
  it('forces standalone output without mutating the input', () => {
    const input = { images: {} };
    const r = applyLaunchroomConfig(input, build, {});
    assert.equal(r.config.output, 'standalone');
    assert.equal((input as { output?: string }).output, undefined);
    assert.deepEqual(ids(r.changes), ['output-standalone']);
    assert.deepEqual(r.warnings, []);
  });

  it('keeps an explicit standalone or export setting', () => {
    assert.deepEqual(applyLaunchroomConfig({ output: 'standalone' }, build, {}).changes, []);
    const exp = applyLaunchroomConfig({ output: 'export' }, build, {});
    assert.equal(exp.config.output, 'export');
    assert.deepEqual(ids(exp.warnings), ['output-export']);
  });

  it('leaves next dev alone', () => {
    const r = applyLaunchroomConfig({}, { ...build, phase: 'phase-development-server' }, { LAUNCHROOM_DEPLOYMENT_ID: 'dpl_1' });
    assert.equal(r.config.output, undefined);
    assert.equal(r.config.deploymentId, undefined);
  });

  it('sets deploymentId from LAUNCHROOM_DEPLOYMENT_ID unless one is set', () => {
    const env = { LAUNCHROOM_DEPLOYMENT_ID: 'dpl_01j9z8x7w6v5t4s3r2q1p0n9n5' };
    assert.equal(applyLaunchroomConfig({}, build, env).config.deploymentId, env.LAUNCHROOM_DEPLOYMENT_ID);

    const kept = applyLaunchroomConfig({ deploymentId: 'mine' }, build, env);
    assert.equal(kept.config.deploymentId, 'mine');
    assert.deepEqual(ids(kept.warnings), ['deployment-id-kept']);

    const same = applyLaunchroomConfig({ deploymentId: env.LAUNCHROOM_DEPLOYMENT_ID }, build, env);
    assert.deepEqual(same.warnings, []);

    const bad = applyLaunchroomConfig({}, build, { LAUNCHROOM_DEPLOYMENT_ID: 'x/../y?' });
    assert.equal(bad.config.deploymentId, undefined);
    assert.deepEqual(ids(bad.warnings), ['deployment-id-invalid']);
  });

  it('sets outputFileTracingRoot and turbopack.root together for a repo root above the project', () => {
    const r = applyLaunchroomConfig(
      { outputFileTracingRoot: '/', turbopack: { root: '/', rules: {} } },
      { ...build, projectDir: '/repo/apps/web' },
      { LAUNCHROOM_REPO_ROOT: '/repo' },
    );
    assert.equal(r.config.outputFileTracingRoot, '/repo');
    assert.deepEqual(r.config.turbopack, { root: '/repo', rules: {} });
    assert.deepEqual(ids(r.changes), ['output-standalone', 'tracing-root']);
  });

  it('refuses a repo root that does not contain the project', () => {
    const outside = applyLaunchroomConfig({ outputFileTracingRoot: '/app' }, build, { LAUNCHROOM_REPO_ROOT: '/other' });
    assert.equal(outside.config.outputFileTracingRoot, '/app');
    assert.deepEqual(ids(outside.warnings), ['repo-root-outside']);
    assert.deepEqual(ids(applyLaunchroomConfig({}, build, { LAUNCHROOM_REPO_ROOT: 'relative' }).warnings), ['repo-root-invalid']);
  });

  it('reports the missing shared cache handler', () => {
    assert.deepEqual(ids(applyLaunchroomConfig({}, build, { LAUNCHROOM_NEXT_CACHE: 'redis' }).warnings), ['cache-handler-missing']);
    const own = applyLaunchroomConfig({ cacheHandlers: { default: '/app/cache.js' } }, build, { LAUNCHROOM_NEXT_CACHE: 'redis' });
    assert.deepEqual(own.warnings, []);
    assert.ok(ids(own.changes).includes('cache-handler-kept'));
    assert.deepEqual(applyLaunchroomConfig({}, build, { LAUNCHROOM_NEXT_CACHE: 'local' }).warnings, []);
  });
});
