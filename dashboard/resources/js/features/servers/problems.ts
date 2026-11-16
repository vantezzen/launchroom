/**
 * Known problems as product sentences (docs/08 §14): the UI maps the
 * state that carries a problem (the mesh error, an edge's certificate
 * storage, a server's private network state) to a sentence and its
 * consequence. The raw text stays behind "Details" (ErrorDetails).
 */
export type Problem = { title: string; detail: string };

function list(names: string[]): string {
    return names.length <= 2
        ? names.join(' and ')
        : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

/** `MeshOverviewData.error`: the private network's coordinator (Headscale) failed. */
export const meshProblem: Problem = {
    title: 'launchroom can’t manage the private network right now.',
    detail: 'Connected servers keep reaching each other. New servers can’t join until this is fixed.',
};

/** Edges whose certificate storage is `degraded` (serving their local copy). */
export function storageProblem(names: string[]): Problem {
    return {
        title:
            names.length === 1
                ? `Certificates on ${names[0]} come from a local copy.`
                : `Certificates on ${list(names)} come from local copies.`,
        detail: 'Sites stay up. New certificates wait until shared certificate storage can be reached again.',
    };
}

/** One edge's certificate storage line (server page, Settings → Network). */
export const storageDegraded =
    'Shared certificate storage can’t be reached, so this server uses its own copy. Sites stay up. New certificates wait until the storage is back.';

/** A server's private network state when it isn't connected (`ServerMeshData.message` goes under Details). */
export const meshMemberProblems: Record<
    'connecting' | 'error',
    { label: string; detail: string }
> = {
    connecting: {
        label: 'Joining',
        detail: 'Joining the private network. It gets its network key with the next report.',
    },
    error: {
        label: 'Can’t join',
        detail: 'This server can’t join the private network. Your other servers can’t reach its apps until it does.',
    },
};
