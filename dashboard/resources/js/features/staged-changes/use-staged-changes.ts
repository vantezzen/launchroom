import { router } from '@inertiajs/react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { requestJson } from '@/features/projects/http';
import { summary } from '@/routes/staged-changes';

export type StagedChanges = App.Data.StagedChanges.StagedChangesData;

export function stagedChangesKey(environment: string) {
    return ['staged-changes', environment] as const;
}

/**
 * The environment's staged changes (count, groups, effects). Refetched after
 * Inertia form submissions (edits elsewhere on the page stage changes), on
 * window focus and on realtime canvas changes (StagedChangesPill). Plain
 * navigations (opening a panel, switching tabs, live refreshes) change
 * nothing staged and don't refetch it.
 */
export function useStagedChanges(project: string, environment: string) {
    const queryClient = useQueryClient();
    const query = useQuery({
        queryKey: stagedChangesKey(environment),
        queryFn: ({ signal }) =>
            requestJson<StagedChanges>(
                'get',
                summary({ project, environment }).url,
                undefined,
                signal,
            ),
    });

    useEffect(
        () =>
            router.on('finish', (event) => {
                const visit = event.detail.visit;

                if (visit.method !== 'get' && visit.completed) {
                    void queryClient.invalidateQueries({
                        queryKey: stagedChangesKey(environment),
                    });
                }
            }),
        [queryClient, environment],
    );

    return query;
}

const effectLabels: Record<App.Enums.ChangeEffect, string> = {
    none: 'no restart',
    route: 'routing',
    restart: 'restart',
    rebuild: 'rebuild',
    new: 'new',
    remove: 'remove',
};

export function effectLabel(effect: App.Enums.ChangeEffect): string {
    return effectLabels[effect];
}

/** "web", "web and api", "web, api and worker". */
function names(list: string[]): string {
    return list.length < 2
        ? (list[0] ?? '')
        : `${list.slice(0, -1).join(', ')} and ${list[list.length - 1]}`;
}

/** What applying does to services, most disruptive first. */
const effectVerbs: [App.Enums.ChangeEffect, string][] = [
    ['rebuild', 'rebuilds'],
    ['restart', 'restarts'],
    ['new', 'starts'],
    ['remove', 'removes'],
    ['route', 'updates the routing of'],
];

/**
 * Which services applying the staged changes redeploys, in a sentence
 * (docs/04 §7, D75): "Applying rebuilds web and restarts api and worker."
 * Empty when no service restarts.
 */
export function effectSummary(changes: StagedChanges): string {
    const parts = effectVerbs.flatMap(([effect, verb]) => {
        const services = changes.groups
            .filter(
                (group) => group.serviceId !== null && group.effect === effect,
            )
            .map((group) => group.name);

        return services.length > 0 ? [`${verb} ${names(services)}`] : [];
    });

    if (parts.length === 0) {
        return '';
    }

    const last = parts.pop();

    return parts.length === 0
        ? `Applying ${last}.`
        : `Applying ${parts.join(', ')}, and ${last}.`;
}
