/**
 * Recently visited places for ⌘K's "Recent" (docs/08 §8): service panels,
 * project canvases, deployments and servers, recorded from real visits (not
 * only palette picks). Ids only: names, statuses and links are resolved
 * against the palette index when it renders, so a renamed or deleted
 * service, or one of another team, never shows a stale row. Per browser; it
 * may be empty in private windows.
 */
export type RecentVisit = {
    kind: 'service' | 'project' | 'deployment' | 'server';
    id: string;
    project: string | null;
    environment: string | null;
};

const KEY = 'launchroom.recent';
const LIMIT = 8;

const SERVICE =
    /^\/p\/(prj_[0-9A-Z]{26})\/(env_[0-9A-Z]{26})\/s\/(svc_[0-9A-Z]{26})/;
const DEPLOYMENT =
    /^\/p\/(prj_[0-9A-Z]{26})\/(env_[0-9A-Z]{26})\/d\/(dpl_[0-9A-Z]{26})/;
const ENVIRONMENT = /^\/p\/(prj_[0-9A-Z]{26})\/(env_[0-9A-Z]{26})(?:\/|$)/;
const SERVER = /^\/servers\/(srv_[0-9A-Z]{26})(?:\/|$)/;

/** What a page URL is a visit to, if anything worth remembering. */
export function visitOf(url: string): RecentVisit | null {
    const path = url.split(/[?#]/)[0];
    let match = SERVICE.exec(path);

    if (match) {
        return {
            kind: 'service',
            id: match[3],
            project: match[1],
            environment: match[2],
        };
    }

    match = DEPLOYMENT.exec(path);

    if (match) {
        return {
            kind: 'deployment',
            id: match[3],
            project: match[1],
            environment: match[2],
        };
    }

    match = ENVIRONMENT.exec(path);

    if (match) {
        return {
            kind: 'project',
            id: match[1],
            project: match[1],
            environment: match[2],
        };
    }

    match = SERVER.exec(path);

    return match
        ? { kind: 'server', id: match[1], project: null, environment: null }
        : null;
}

export function readRecent(): RecentVisit[] {
    try {
        const parsed: unknown = JSON.parse(
            window.localStorage.getItem(KEY) ?? '[]',
        );

        return Array.isArray(parsed)
            ? (parsed as RecentVisit[])
                  .filter(
                      (item) =>
                          typeof item?.id === 'string' &&
                          typeof item.kind === 'string',
                  )
                  .slice(0, LIMIT)
            : [];
    } catch {
        return [];
    }
}

export function recordVisit(url: string): void {
    const visit = visitOf(url);

    if (!visit) {
        return;
    }

    try {
        const next = [
            visit,
            ...readRecent().filter(
                (entry) =>
                    !(entry.kind === visit.kind && entry.id === visit.id),
            ),
        ].slice(0, LIMIT);
        window.localStorage.setItem(KEY, JSON.stringify(next));
    } catch {
        // Storage unavailable: recent items are optional.
    }
}
