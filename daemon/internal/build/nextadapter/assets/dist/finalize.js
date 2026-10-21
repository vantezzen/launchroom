import fs from 'node:fs';
import path from 'node:path';
import { MANIFEST_FILE } from './manifest.js';
export class FinalizeError extends Error {
    name = 'FinalizeError';
}
function readJSON(file) {
    try {
        return JSON.parse(fs.readFileSync(file, 'utf8'));
    }
    catch {
        return null;
    }
}
function copyDir(src, dest) {
    if (!fs.existsSync(src) || !fs.statSync(src).isDirectory())
        return false;
    fs.rmSync(dest, { recursive: true, force: true });
    fs.mkdirSync(path.dirname(dest), { recursive: true });
    // dereference: symlinks in public/ must not dangle inside the image.
    fs.cpSync(src, dest, { recursive: true, dereference: true, force: true });
    return true;
}
/**
 * Completes a standalone build: copies `public/` and `<distDir>/static` next to
 * the standalone `server.js` (Next leaves them out on purpose: they are meant
 * for a CDN) and places the launchroom manifest there.
 *
 * It has to run after `next build` has exited its standalone step: Next calls
 * the adapter's `onBuildComplete` *before* it (re)writes `.next/standalone`,
 * deleting that directory first (verified for 16.2.0 and 16.4.2). The adapter
 * therefore runs it from a process exit hook, and the `launchroom-next-finalize`
 * CLI runs it explicitly. It is synchronous and idempotent.
 */
export function finalize(opts) {
    const projectDir = path.resolve(opts.projectDir);
    const distDir = path.resolve(projectDir, opts.distDir ?? '.next');
    const manifest = readJSON(path.join(distDir, MANIFEST_FILE));
    // required-server-files.json records where the app sits inside the traced
    // standalone tree (relativeAppDir = projectDir relative to the tracing root).
    const rsf = readJSON(path.join(distDir, 'required-server-files.json'));
    const relativeAppDir = rsf?.relativeAppDir ?? '';
    if (path.isAbsolute(relativeAppDir) || relativeAppDir.split(/[\\/]/).includes('..')) {
        throw new FinalizeError(`unexpected relativeAppDir ${JSON.stringify(relativeAppDir)} in required-server-files.json`);
    }
    const appDir = path.join(distDir, 'standalone', relativeAppDir);
    const serverEntry = path.join(appDir, 'server.js');
    if (!fs.existsSync(serverEntry)) {
        throw new FinalizeError(`${path.relative(projectDir, serverEntry)} does not exist: the build did not produce standalone output (is output: 'standalone' set, and did next build succeed?)`);
    }
    const copiedPublic = copyDir(path.join(projectDir, 'public'), path.join(appDir, 'public'));
    const copiedStatic = copyDir(path.join(distDir, 'static'), path.join(appDir, path.relative(projectDir, distDir), 'static'));
    if (manifest) {
        fs.writeFileSync(path.join(appDir, MANIFEST_FILE), JSON.stringify(manifest, null, 2) + '\n');
    }
    return { appDir, serverEntry, copiedPublic, copiedStatic, manifest };
}
