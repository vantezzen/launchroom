/** Query keys of the Security page; `all` covers every one of them. */
export const securityKeys = {
    all: ['security'] as const,
    overview: (
        range: string,
        service: string | null | undefined,
        project: string | null | undefined,
    ) => ['security', 'overview', range, service, project] as const,
    finding: (id: string | null) => ['security', 'finding', id] as const,
    inventory: (query: string) => ['security', 'inventory', query] as const,
};
