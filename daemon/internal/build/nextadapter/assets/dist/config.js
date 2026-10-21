import path from 'node:path';
/** launchroom deployment ids (and anything else Next embeds as `?dpl=`): URL-safe, bounded. */
const DEPLOYMENT_ID = /^[A-Za-z0-9_-]{1,64}$/;
/**
 * The pure part of `modifyConfig`: returns a new config object (the input is
 * not mutated) plus what was changed and why. Explicit user settings win
 * whenever they are compatible with running on launchroom; incompatible ones
 * are kept and reported as warnings, never silently overridden.
 */
export function applyLaunchroomConfig(input, ctx, env) {
    const config = { ...input };
    const changes = [];
    const warnings = [];
    // `next dev` keeps the user's config untouched (NEXT_ADAPTER_PATH is only set
    // for builds by launchroom, but users may set adapterPath themselves).
    if (ctx.phase === 'phase-development-server') {
        return { config, changes, warnings };
    }
    // 1. Standalone output: small image, `node server.js` without full node_modules.
    if (config.output === undefined || config.output === null) {
        config.output = 'standalone';
        changes.push({ id: 'output-standalone', message: "output: 'standalone'" });
    }
    else if (config.output === 'export') {
        warnings.push({
            id: 'output-export',
            message: "output: 'export' is set: the app is built as a static export, launchroom serves it as static files (no server, no ISR, no middleware).",
        });
    }
    else if (config.output !== 'standalone') {
        warnings.push({ id: 'output-unknown', message: `Unknown output ${JSON.stringify(config.output)} kept; launchroom expects 'standalone'.` });
    }
    // 2. deploymentId: skew protection and consistent asset URLs across replicas.
    const deploymentId = env.LAUNCHROOM_DEPLOYMENT_ID?.trim();
    if (deploymentId) {
        if (!DEPLOYMENT_ID.test(deploymentId)) {
            warnings.push({ id: 'deployment-id-invalid', message: 'LAUNCHROOM_DEPLOYMENT_ID is not a valid deployment id; deploymentId left unchanged.' });
        }
        else if (config.deploymentId && config.deploymentId !== deploymentId) {
            warnings.push({
                id: 'deployment-id-kept',
                message: `deploymentId is already set (${config.deploymentId}, from next.config or NEXT_DEPLOYMENT_ID); keeping it instead of the launchroom deployment id.`,
            });
        }
        else if (!config.deploymentId) {
            config.deploymentId = deploymentId;
            changes.push({ id: 'deployment-id', message: `deploymentId: ${deploymentId}` });
        }
    }
    // 3. Output file tracing root: the root of what is available in the build
    //    (launchroom's build context), so standalone traces workspace packages
    //    and Next does not walk above it looking for lockfiles.
    const repoRoot = env.LAUNCHROOM_REPO_ROOT?.trim();
    if (repoRoot) {
        const rel = path.relative(repoRoot, ctx.projectDir);
        if (!path.isAbsolute(repoRoot)) {
            warnings.push({ id: 'repo-root-invalid', message: 'LAUNCHROOM_REPO_ROOT must be an absolute path; outputFileTracingRoot left unchanged.' });
        }
        else if (rel.startsWith('..') || path.isAbsolute(rel)) {
            warnings.push({ id: 'repo-root-outside', message: `The project (${ctx.projectDir}) is not inside LAUNCHROOM_REPO_ROOT (${repoRoot}); outputFileTracingRoot left unchanged.` });
        }
        else if (config.outputFileTracingRoot !== repoRoot) {
            // Next resolves both before modifyConfig and requires them to match.
            config.outputFileTracingRoot = repoRoot;
            config.turbopack = { ...(config.turbopack ?? {}), root: repoRoot };
            changes.push({ id: 'tracing-root', message: `outputFileTracingRoot: ${repoRoot}` });
        }
    }
    // 4. Shared cache for ISR / revalidateTag across replicas (documented TODO:
    //    launchroom does not ship a cache handler yet; see README).
    const cache = env.LAUNCHROOM_NEXT_CACHE?.trim();
    if (cache && cache !== 'local') {
        const hasHandler = Boolean(config.cacheHandler) || Object.values(config.cacheHandlers ?? {}).some(Boolean);
        if (hasHandler) {
            changes.push({ id: 'cache-handler-kept', message: 'Using the cache handler from next.config.' });
        }
        else {
            warnings.push({
                id: 'cache-handler-missing',
                message: `LAUNCHROOM_NEXT_CACHE=${cache} is not supported by this adapter version yet; ISR and revalidateTag stay per replica. Configure cacheHandler/cacheHandlers in next.config.`,
            });
        }
    }
    return { config, changes, warnings };
}
export function logConfigResult(result, log) {
    for (const c of result.changes)
        log(`[launchroom] ${c.message}`);
    for (const w of result.warnings)
        log(`[launchroom] warning: ${w.message}`);
}
