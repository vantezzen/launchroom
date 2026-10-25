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
 * every Inertia visit (edits elsewhere on the page), on window focus and on
 * realtime canvas changes.
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
            router.on('success', () => {
                void queryClient.invalidateQueries({
                    queryKey: stagedChangesKey(environment),
                });
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

/**
 * "web: rebuild, api: restart" (docs/04 §7).
 */
export function effectSummary(changes: StagedChanges): string {
    return changes.groups
        .filter((group) => group.serviceId !== null && group.effect !== 'none')
        .map(
            (group) =>
                `${group.slug ?? group.name}: ${effectLabel(group.effect)}`,
        )
        .join(', ');
}
