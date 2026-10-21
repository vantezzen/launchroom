import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

import adapter, { createAdapter, finalize, FinalizeError, MANIFEST_FILE, SUMMARY_MARKER, VERSION } from '../dist/index.js';
import { buildCtx, fakeStandaloneBuild, sampleOutputs, tmpProject, write } from './helpers.ts';

const pkgDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

describe('finalize', () => {
  it('copies public/ and .next/static next to server.js, idempotently', () => {
    const dir = tmpProject();
    fakeStandaloneBuild(dir);
    for (let i = 0; i < 2; i++) {
      const r = finalize({ projectDir: dir });
      assert.equal(r.serverEntry, path.join(dir, '.next/standalone/server.js'));
      assert.ok(r.copiedPublic && r.copiedStatic);
    }
    assert.equal(fs.readFileSync(path.join(dir, '.next/standalone/public/robots.txt'), 'utf8'), 'User-agent: *');
    assert.equal(fs.readFileSync(path.join(dir, '.next/standalone/.next/static/chunks/main-abc123.js'), 'utf8'), 'chunk');
  });

  it('follows relativeAppDir for monorepos and works without public/', () => {
    const dir = tmpProject();
    fakeStandaloneBuild(dir, 'apps/web');
    fs.rmSync(path.join(dir, 'public'), { recursive: true });
    const r = finalize({ projectDir: dir });
    assert.equal(r.appDir, path.join(dir, '.next/standalone/apps/web'));
    assert.equal(r.copiedPublic, false);
    assert.ok(fs.existsSync(path.join(r.appDir, '.next/static/chunks/main-abc123.js')));
  });

  it('fails without standalone output or with an escaping relativeAppDir', () => {
    const dir = tmpProject();
    assert.throws(() => finalize({ projectDir: dir }), FinalizeError);
    write(path.join(dir, '.next/required-server-files.json'), JSON.stringify({ relativeAppDir: '../../etc' }));
    assert.throws(() => finalize({ projectDir: dir }), /unexpected relativeAppDir/);
  });
});

describe('adapter', () => {
  it('is a NextAdapter default export', () => {
    assert.equal(adapter.name, 'launchroom');
    assert.equal(typeof adapter.modifyConfig, 'function');
    assert.equal(typeof adapter.onBuildComplete, 'function');
  });

  it('keeps VERSION equal to package.json', () => {
    const pkg = JSON.parse(fs.readFileSync(path.join(pkgDir, 'package.json'), 'utf8'));
    assert.equal(VERSION, pkg.version);
  });

  it('modifyConfig logs only during production builds', async () => {
    const lines: string[] = [];
    const a = createAdapter({ env: {}, log: (m) => lines.push(m), finalizeOnExit: false });
    await a.modifyConfig!({}, { phase: 'phase-production-server', nextVersion: '16.4.2', projectDir: '/app' });
    assert.deepEqual(lines, []);
    const cfg = await a.modifyConfig!({}, { phase: 'phase-production-build', nextVersion: '16.4.2', projectDir: '/app' });
    assert.equal(cfg.output, 'standalone');
    assert.deepEqual(lines, ["[launchroom] output: 'standalone'"]);
  });

  it('onBuildComplete writes the manifest into distDir', async () => {
    const dir = tmpProject();
    const a = createAdapter({ env: {}, log: () => {}, finalizeOnExit: false });
    await a.onBuildComplete!(buildCtx(dir, { outputs: sampleOutputs() }));
    const m = JSON.parse(fs.readFileSync(path.join(dir, '.next', MANIFEST_FILE), 'utf8'));
    assert.equal(m.prerenders.isr[0].pathname, '/isr');
  });

  // Next calls onBuildComplete BEFORE it writes .next/standalone (and wipes
  // that directory first), so the copy must happen at process exit.
  it('finalizes on a successful exit, after Next wrote the standalone directory', () => {
    const dir = tmpProject();
    const script = `
      import { createAdapter } from ${JSON.stringify(path.join(pkgDir, 'dist/index.js'))};
      import fs from 'node:fs';
      const dir = ${JSON.stringify(dir)};
      const a = createAdapter({ env: {} });
      await a.onBuildComplete({ outputs: { pages: [], pagesApi: [], appPages: [], appRoutes: [], prerenders: [], staticFiles: [] },
        projectDir: dir, repoRoot: dir, distDir: dir + '/.next', config: { output: 'standalone', outputFileTracingRoot: dir },
        nextVersion: '16.4.2', buildId: 'b' });
      // what next build does after the hook:
      fs.rmSync(dir + '/.next/standalone', { recursive: true, force: true });
      fs.mkdirSync(dir + '/.next/standalone', { recursive: true });
      fs.writeFileSync(dir + '/.next/standalone/server.js', '');
      fs.writeFileSync(dir + '/.next/required-server-files.json', '{"relativeAppDir":""}');
      fs.mkdirSync(dir + '/.next/static', { recursive: true });
      fs.writeFileSync(dir + '/.next/static/x.js', 'x');
      process.exit(0);
    `;
    const r = spawnSync(process.execPath, ['--input-type=module', '-e', script], { encoding: 'utf8' });
    assert.equal(r.status, 0, r.stderr + r.stdout);
    assert.match(r.stdout, /standalone output ready/);
    assert.ok(fs.existsSync(path.join(dir, '.next/standalone/.next/static/x.js')));
    assert.ok(fs.existsSync(path.join(dir, '.next/standalone', MANIFEST_FILE)));
  });

  it('turns a failed finalize into a failed build', () => {
    const dir = tmpProject();
    const script = `
      import { createAdapter } from ${JSON.stringify(path.join(pkgDir, 'dist/index.js'))};
      const dir = ${JSON.stringify(dir)};
      await createAdapter({ env: {} }).onBuildComplete({ outputs: { pages: [], pagesApi: [], appPages: [], appRoutes: [], prerenders: [], staticFiles: [] },
        projectDir: dir, repoRoot: dir, distDir: dir + '/.next', config: { output: 'standalone' }, nextVersion: '16.4.2', buildId: 'b' });
    `;
    const r = spawnSync(process.execPath, ['--input-type=module', '-e', script], { encoding: 'utf8' });
    assert.equal(r.status, 1);
    assert.match(r.stdout, /server\.js does not exist/);
  });
});

describe('launchroom-next-finalize CLI', () => {
  it('finalizes and prints the summary line', () => {
    const dir = tmpProject();
    fakeStandaloneBuild(dir);
    const a = createAdapter({ env: {}, log: () => {}, finalizeOnExit: false });
    void a.onBuildComplete!(buildCtx(dir, { outputs: sampleOutputs() }));
    const r = spawnSync(process.execPath, [path.join(pkgDir, 'dist/cli.js'), dir], { encoding: 'utf8' });
    assert.equal(r.status, 0, r.stderr);
    const line = r.stdout.split('\n').find((l) => l.startsWith(SUMMARY_MARKER));
    assert.ok(line, r.stdout);
    const s = JSON.parse(line.slice(SUMMARY_MARKER.length));
    assert.equal(s.v, 1);
    assert.equal(s.serverEntry, '.next/standalone/server.js');
    assert.deepEqual(s.isrRoutes, ['/isr']);
    assert.equal(s.middleware, true);
  });

  it('exits 1 without standalone output', () => {
    const r = spawnSync(process.execPath, [path.join(pkgDir, 'dist/cli.js'), tmpProject()], { encoding: 'utf8' });
    assert.equal(r.status, 1);
    assert.match(r.stderr, /does not exist/);
  });
});
