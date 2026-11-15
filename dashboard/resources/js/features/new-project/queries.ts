import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { RequestFailed, requestJson } from '@/lib/http';
import { branches, index as repositories } from '@/routes/git/repositories';
import { preview as composePreview } from '@/routes/projects/compose';
import { inspect } from '@/routes/projects/import';

type Repository = App.Data.Git.GitRepositoryData;
type Inspection = App.Data.Projects.RepositoryInspectionData;
type ComposePreview = App.Data.Blueprints.ComposePreviewData;

const newProjectKeys = {
    all: ['new-project'] as const,
    repositories: () => [...newProjectKeys.all, 'repositories'] as const,
    branches: (repository: string) =>
        [...newProjectKeys.all, 'branches', repository] as const,
    inspection: (repository: string, branch: string) =>
        [...newProjectKeys.all, 'inspection', repository, branch] as const,
    composePreview: (payload: unknown) =>
        [...newProjectKeys.all, 'compose-preview', payload] as const,
};

export function failureMessage(
    error: unknown,
    fallback: string,
): string | null {
    if (error === null || error === undefined) {
        return null;
    }

    return error instanceof RequestFailed ? error.message : fallback;
}

/**
 * The repository a reload or Back comes back to (`?repository=…`): null
 * when there is none or it is no longer listed.
 */
export function useRepositoryById(id: string | null) {
    return useQuery({
        queryKey: newProjectKeys.repositories(),
        queryFn: ({ signal }) =>
            requestJson<{ repositories: Repository[] }>(
                'get',
                repositories().url,
                undefined,
                signal,
            ),
        enabled: id !== null,
        retry: false,
        select: (data) =>
            data.repositories.find((repository) => repository.id === id) ??
            null,
    });
}

/** The branch names of a repository, for the switcher. */
export function useRepositoryBranches(repository: string | null) {
    return useQuery({
        queryKey: newProjectKeys.branches(repository ?? ''),
        queryFn: ({ signal }) =>
            requestJson<{ branches: { name: string }[] }>(
                'get',
                branches(repository ?? '').url,
                undefined,
                signal,
            ).then((data) => data.branches.map((branch) => branch.name)),
        enabled: repository !== null,
        retry: false,
    });
}

/**
 * "We looked at your repo": apps and suggested databases. Kept until the
 * page goes away, so editing the choices never races a refetch.
 */
export function useRepositoryInspection(
    repository: string | null,
    branch: string,
) {
    return useQuery({
        queryKey: newProjectKeys.inspection(repository ?? '', branch),
        queryFn: ({ signal }) =>
            requestJson<Inspection>(
                'get',
                inspect({ query: { repository: repository ?? '', branch } })
                    .url,
                undefined,
                signal,
            ),
        enabled: repository !== null && branch !== '',
        retry: false,
        staleTime: Infinity,
        refetchOnWindowFocus: false,
    });
}

function useDebounced<T>(value: T, ms: number): T {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(value), ms);

        return () => window.clearTimeout(timer);
    }, [value, ms]);

    return debounced;
}

/**
 * What a Compose file becomes, half a second after the last edit. The last
 * answer stays on screen while the next one loads.
 */
export function useComposePreview(payload: object, ready: boolean) {
    const debounced = useDebounced(payload, 500);

    return useQuery({
        queryKey: newProjectKeys.composePreview(debounced),
        queryFn: ({ signal }) =>
            requestJson<ComposePreview>(
                'post',
                composePreview().url,
                debounced,
                signal,
            ),
        enabled: ready,
        retry: false,
        staleTime: Infinity,
        refetchOnWindowFocus: false,
        placeholderData: keepPreviousData,
    });
}
