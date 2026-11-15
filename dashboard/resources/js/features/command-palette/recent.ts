/**
 * Recently visited places for ⌘K's "Recent" (docs/08 §8): service panels,
 * project pages, deployments and servers, recorded from real visits (not
 * only palette picks) by the page and its props. Ids only: names, statuses and links are resolved
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

type VisitedPage = { component: string; props: object };

type VisitProps = Partial<{
    project: { id: string };
    environment: { id: string } | null;
    panel: { serviceId: string } | null;
    detail: { deployment: { id: string } };
    server: { id: string };
}>;

/** What a page is a visit to, if anything worth remembering. */
function visitOf(page: VisitedPage): RecentVisit | null {
    const props = page.props as VisitProps;

    if (page.component === 'servers/show' && props.server) {
        return {
            kind: 'server',
            id: props.server.id,
            project: null,
            environment: null,
        };
    }

    const project = props.project?.id;
    const environment = props.environment?.id;

    if (!project || !environment) {
        return null;
    }

    if (page.component === 'projects/canvas' && props.panel) {
        return {
            kind: 'service',
            id: props.panel.serviceId,
            project,
            environment,
        };
    }

    if (page.component === 'projects/deployments/show' && props.detail) {
        return {
            kind: 'deployment',
            id: props.detail.deployment.id,
            project,
            environment,
        };
    }

    return { kind: 'project', id: project, project, environment };
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

export function recordVisit(page: VisitedPage): void {
    const visit = visitOf(page);

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
