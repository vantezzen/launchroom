import { createRequire } from 'node:module';
import path from 'node:path';
import { ADAPTER_NAME, VERSION } from './version.js';
export const MANIFEST_FILE = 'launchroom-next-manifest.json';
/** Prefix of the one-line build summary the finalize CLI prints for launchroomd. */
export const SUMMARY_MARKER = 'launchroom-next-summary ';
const MAX_ROUTES = 500;
const MAX_SUMMARY_ISR = 32;
const MAX_MATCHERS = 32;
/** Internal, generated and variant outputs that are not user-facing routes. */
function isVariant(pathname) {
    return pathname.endsWith('.rsc') || pathname.includes('.segments/') || pathname.endsWith('.prefetch.rsc') || pathname.startsWith('/_next/');
}
function isInternal(pathname) {
    return /^\/(_not-found|_global-error|_error|_app|_document|404|500)(\/|$)/.test(pathname);
}
function hasSharp(projectDir) {
    try {
        createRequire(path.join(projectDir, 'package.json')).resolve('sharp');
        return true;
    }
    catch {
        return false;
    }
}
function isrRevalidate(p) {
    const r = p.fallback?.initialRevalidate;
    return typeof r === 'number' && r > 0 ? r : null;
}
export function standalonePaths(ctx) {
    if (ctx.config.output !== 'standalone')
        return null;
    const root = ctx.config.outputFileTracingRoot || ctx.projectDir;
    const appDir = path.join(ctx.distDir, 'standalone', path.relative(root, ctx.projectDir));
    const rel = (p) => path.relative(ctx.projectDir, p).split(path.sep).join('/');
    return { appDir: rel(appDir) || '.', serverEntry: rel(path.join(appDir, 'server.js')) };
}
export function buildManifest(ctx) {
    const { outputs, config } = ctx;
    const warnings = [];
    const list = [];
    const count = { appPages: 0, appRoutes: 0, pages: 0, pagesApi: 0, edge: 0 };
    const groups = [
        ['app-page', 'appPages', outputs.appPages ?? []],
        ['app-route', 'appRoutes', outputs.appRoutes ?? []],
        ['page', 'pages', outputs.pages ?? []],
        ['pages-api', 'pagesApi', outputs.pagesApi ?? []],
    ];
    const seen = new Set();
    for (const [kind, key, items] of groups) {
        for (const o of items) {
            if (isVariant(o.pathname) || isInternal(o.pathname))
                continue;
            const id = kind + ' ' + o.pathname;
            if (seen.has(id))
                continue;
            seen.add(id);
            count[key]++;
            if (o.runtime === 'edge')
                count.edge++;
            if (list.length < MAX_ROUTES)
                list.push({ pathname: o.pathname, kind, runtime: o.runtime });
        }
    }
    list.sort((a, b) => a.pathname.localeCompare(b.pathname) || a.kind.localeCompare(b.kind));
    if (count.edge > 0) {
        warnings.push(`${count.edge} route(s) use the edge runtime; on launchroom they run inside the Node.js server like any other route.`);
    }
    const isr = [];
    let staticCount = 0;
    const seenPrerender = new Set();
    for (const p of outputs.prerenders ?? []) {
        if (isVariant(p.pathname) || isInternal(p.pathname) || seenPrerender.has(p.pathname))
            continue;
        seenPrerender.add(p.pathname);
        const revalidate = isrRevalidate(p);
        if (revalidate === null) {
            staticCount++;
        }
        else if (isr.length < MAX_ROUTES) {
            isr.push({ pathname: p.pathname, route: p.route ?? null, revalidate });
        }
    }
    isr.sort((a, b) => a.pathname.localeCompare(b.pathname));
    const mw = outputs.middleware;
    const matchers = (mw?.config?.matchers ?? [])
        .map((m) => m.source ?? m.sourceRegex ?? '')
        .filter(Boolean)
        .slice(0, MAX_MATCHERS);
    const images = config.images ?? {};
    const loader = typeof images.loader === 'string' ? images.loader : 'default';
    const cacheHandler = Boolean(config.cacheHandler) || Object.values(config.cacheHandlers ?? {}).some(Boolean);
    if (isr.length > 0 && !cacheHandler) {
        warnings.push('ISR routes without a shared cache handler: with more than one replica each keeps its own cache, so revalidation is per replica.');
    }
    if (config.output !== 'standalone' && config.output !== 'export') {
        warnings.push("output is not 'standalone'; the image ships the full node_modules.");
    }
    return {
        schemaVersion: 1,
        adapter: { name: ADAPTER_NAME, version: VERSION },
        nextVersion: ctx.nextVersion,
        buildId: ctx.buildId,
        deploymentId: config.deploymentId || null,
        output: config.output ?? null,
        standalone: standalonePaths(ctx),
        middleware: { present: Boolean(mw), runtime: mw?.runtime ?? null, matchers },
        imageOptimization: {
            enabled: images.unoptimized !== true && loader === 'default',
            loader,
            sharp: hasSharp(ctx.projectDir),
            remotePatterns: (images.remotePatterns?.length ?? 0) + (images.domains?.length ?? 0),
        },
        cacheHandler,
        routes: {
            ...count,
            staticFiles: (outputs.staticFiles ?? []).length,
            truncated: seen.size > list.length,
            list,
        },
        prerenders: { static: staticCount, isr },
        warnings,
    };
}
export function summarize(m) {
    return {
        v: 1,
        adapterVersion: m.adapter.version,
        nextVersion: m.nextVersion,
        deploymentId: m.deploymentId,
        serverEntry: m.standalone?.serverEntry ?? null,
        middleware: m.middleware.present,
        middlewareRuntime: m.middleware.runtime,
        imageOptimization: m.imageOptimization.enabled,
        sharp: m.imageOptimization.sharp,
        cacheHandler: m.cacheHandler,
        routes: {
            appPages: m.routes.appPages,
            appRoutes: m.routes.appRoutes,
            pages: m.routes.pages,
            pagesApi: m.routes.pagesApi,
            staticFiles: m.routes.staticFiles,
            prerendered: m.prerenders.static,
            isr: m.prerenders.isr.length,
            edge: m.routes.edge,
        },
        isrRoutes: m.prerenders.isr
            .map((r) => r.pathname)
            .filter((p) => p.length <= 256)
            .slice(0, MAX_SUMMARY_ISR),
    };
}
