import { router } from '@inertiajs/react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { requestJson } from '@/lib/http';
import { summary } from '@/routes/staged-changes';
import { listNames } from '@/lib/format';

export type StagedChanges = App.Data.StagedChanges.StagedChangesData;

/** Covers the summary and its blockers. */
export function stagedChangesKey(environment: string) {
    return ['staged-changes', environment] as const;
}

export function stagedBlockersKey(environment: string) {
    return [...stagedChangesKey(environment), 'blockers'] as const;
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

        return services.length > 0 ? [`${verb} ${listNames(services)}`] : [];
    });

    if (parts.length === 0) {
        return '';
    }

    const last = parts.pop();

    return parts.length === 0
        ? `Applying ${last}.`
        : `Applying ${parts.join(', ')}, and ${last}.`;
}

/**
 * The staged bar's count: "2 changes", or "4 changes in 2 services" when
 * more than one service has some, so Apply's scope is in the sentence.
 */
export function stagedCountLabel(changes: StagedChanges): string {
    const count = `${changes.count} ${changes.count === 1 ? 'change' : 'changes'}`;
    const services = changes.groups.filter(
        (group) => group.serviceId !== null,
    ).length;

    return services > 1 ? `${count} in ${services} services` : count;
}
