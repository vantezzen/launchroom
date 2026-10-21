import { ArrowUpRight, FileCode2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { shortSha } from '@/features/deployments/format';

type Managed = App.Data.ConfigAsCode.ManagedByFileData;

/** Settings pages and the settings paths of launchroom.json they show. */
const PAGES: Record<string, (field: string) => boolean> = {
    general: (field) => field === 'name' || field === 'enabled',
    source: (field) =>
        field === 'rootDirectory' || field === 'image' || field === 'branch',
    build: (field) => field.startsWith('build.'),
    deploy: (field) =>
        field.startsWith('deploy.') &&
        !['deploy.schedule', 'deploy.entrypoint', 'deploy.capabilities'].some(
            (other) => field === other || field.startsWith(`${other}.`),
        ),
    schedule: (field) =>
        field === 'deploy.schedule' || field.startsWith('deploy.schedule.'),
    advanced: (field) =>
        field === 'deploy.entrypoint' || field === 'deploy.capabilities',
    scaling: (field) => field.startsWith('scaling.'),
    volumes: (field) => field === 'volumes',
    networking: (field) => field === 'connections',
};

/** Fields of `page` that come from the file (domains are listed by hostname). */
export function managedOn(managed: Managed | null, page: string): string[] {
    if (!managed) {
        return [];
    }

    if (page === 'domains') {
        return managed.domains;
    }

    const matches = PAGES[page];

    return matches
        ? managed.fields
              .filter(matches)
              .map((field) => field.replace(/^(build|deploy|scaling)\./, ''))
        : [];
}

/**
 * "Managed by launchroom.json" (docs/18 §3.5): a calm callout above a
 * settings page whose settings come from the file, and the page's inputs
 * read-only. Edits happen in the file; what it says wins on the next push.
 */
export function ManagedByFile({
    managed,
    page,
    children,
}: {
    managed: Managed | null;
    page: string;
    children: ReactNode;
}) {
    const fields = managedOn(managed, page);

    if (!managed || fields.length === 0) {
        return <>{children}</>;
    }

    return (
        <div className="flex flex-col gap-6">
            <ManagedByFileCallout managed={managed} fields={fields} />
            <fieldset
                disabled
                aria-describedby="managed-by-file"
                className="contents"
            >
                {children}
            </fieldset>
        </div>
    );
}

export function ManagedByFileCallout({
    managed,
    fields,
}: {
    managed: Managed;
    fields: string[];
}) {
    const where = managed.repository
        ? `${managed.path} in ${managed.repository}`
        : managed.path;

    return (
        <div
            id="managed-by-file"
            role="note"
            className="flex flex-col gap-3 rounded-xl border bg-card p-4 sm:flex-row sm:items-start sm:gap-4"
        >
            <span
                aria-hidden="true"
                className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-recessed text-muted-foreground max-sm:hidden [&_svg]:size-4"
            >
                <FileCode2 />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <div className="flex flex-col gap-0.5">
                    <p className="type-body font-medium">
                        Managed by {managed.path}
                    </p>
                    <p className="max-w-[60ch] type-small text-muted-foreground">
                        These settings come from {where}
                        {managed.commitSha
                            ? ` at ${shortSha(managed.commitSha)}`
                            : ''}
                        . Edit them in the file: what it says wins on the next
                        push.
                    </p>
                    {managed.status === 'invalid' && (
                        <p className="type-small text-status-progress">
                            The latest version of the file has problems, so
                            these come from the last one that worked.
                        </p>
                    )}
                </div>
                <ul
                    aria-label="Settings from the file"
                    className="flex flex-wrap gap-1.5"
                >
                    {fields.map((field) => (
                        <li
                            key={field}
                            className="rounded-md bg-recessed px-1.5 py-0.5 type-mono-small text-muted-foreground"
                        >
                            {field}
                        </li>
                    ))}
                </ul>
            </div>
            {managed.editUrl && (
                <a
                    href={managed.editUrl}
                    target="_blank"
                    rel="noreferrer noopener"
                    className="inline-flex h-8 shrink-0 items-center gap-1 self-start rounded-lg border bg-background px-3 type-label hover:bg-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    Edit in {managed.path.split('/').pop()}
                    <ArrowUpRight aria-hidden="true" className="size-3.5" />
                </a>
            )}
        </div>
    );
}
