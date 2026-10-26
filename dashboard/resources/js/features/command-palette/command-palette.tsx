import { router, usePage } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import {
    Activity,
    Boxes,
    FolderKanban,
    History,
    Layers,
    Rocket,
    RotateCcw,
    ScrollText,
    Server,
    Settings,
    SquarePlus,
    Undo2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { ProjectIcon } from '@/components/project-icon';
import { Status, deploymentStatus, serverStatus } from '@/components/status';
import { useEffect, useMemo, useState } from 'react';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
    CommandShortcut,
} from '@/components/ui/command';
import { requestJson } from '@/features/projects/http';
import {
    project as projectDeployments,
    rollback,
    show as deploymentShow,
} from '@/routes/deployments';
import { redeploy as redeployService } from '@/routes/deployments/service';
import { index as paletteIndex } from '@/routes/palette';
import { index as activityIndex } from '@/routes/activity';
import {
    canvas,
    logs as projectLogs,
    index as projectsIndex,
} from '@/routes/projects';
import {
    section as serviceSection,
    show as servicePanel,
} from '@/routes/projects/services';
import { index as serversIndex, show as serverShow } from '@/routes/servers';
import { edit as teamSettings } from '@/routes/team';
import { addRecent, readRecent } from './recent';
import type { RecentItem } from './recent';

type Palette = App.Data.CommandPalette.PaletteData;
type Item = App.Data.CommandPalette.PaletteItemData;

const OPEN_EVENT = 'launchroom:command-palette';

/** Opens ⌘K from anywhere (e.g. a top-bar button). */
export function openCommandPalette(search = '') {
    window.dispatchEvent(new CustomEvent(OPEN_EVENT, { detail: search }));
}

type Context = {
    project: string | null;
    environment: string | null;
    service: string | null;
    deployment: App.Data.Deployments.DeploymentSummaryData | null;
};

const ROUTE =
    /^\/p\/(prj_[0-9A-Z]{26})(?:\/(env_[0-9A-Z]{26}))?(?:\/s\/(svc_[0-9A-Z]{26}))?/;

function useContext(): Context {
    const page = usePage<{
        detail?: { deployment?: App.Data.Deployments.DeploymentSummaryData };
    }>();
    const match = ROUTE.exec(page.url.split('?')[0]);

    return {
        project: match?.[1] ?? null,
        environment: match?.[2] ?? null,
        service: match?.[3] ?? null,
        deployment: page.props.detail?.deployment ?? null,
    };
}

type Confirm = { label: string; run: () => void };

/** Status in the unified vocabulary (design-system §3.3), icon + word. */
function PaletteStatus({ kind, status }: { kind: string; status: string }) {
    const mapped =
        kind === 'deployment'
            ? deploymentStatus[status as App.Enums.DeploymentStatus]
            : kind === 'server'
              ? serverStatus[status as App.Enums.ServerStatus]
              : undefined;

    if (!mapped) {
        return null;
    }

    return (
        <Status
            state={mapped.state}
            label={mapped.label}
            className="ml-auto shrink-0 type-small text-muted-foreground"
        />
    );
}

function isTyping(target: EventTarget | null): boolean {
    const element = target as HTMLElement | null;

    return Boolean(
        element?.closest('input, textarea, select, [contenteditable="true"]'),
    );
}

/**
 * ⌘K (docs/08 §4, design-system §6): jump to projects, environments,
 * services, deployments and servers; run actions (redeploy, roll back, open
 * logs, add a variable, switch environment); recent items first. Also binds
 * `g p` projects, `g s` servers, `g l` logs, `g d` deployments and `g v`
 * (variables of the open service).
 */
export function CommandPalette() {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [confirm, setConfirm] = useState<Confirm | null>(null);
    const [recent, setRecent] = useState<RecentItem[]>([]);
    const context = useContext();

    const { data } = useQuery({
        queryKey: ['command-palette'],
        queryFn: ({ signal }) =>
            requestJson<Palette>('get', paletteIndex().url, undefined, signal),
        enabled: open,
        staleTime: 30_000,
    });

    useEffect(() => {
        const onOpen = (event: Event) => {
            setSearch((event as CustomEvent<string>).detail ?? '');
            setOpen(true);
        };

        let leader = 0;

        const onKey = (event: KeyboardEvent) => {
            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                setOpen((current) => !current);

                return;
            }

            if (
                isTyping(event.target) ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey
            ) {
                return;
            }

            if (event.key === 'g') {
                leader = Date.now();

                return;
            }

            if (Date.now() - leader > 1000) {
                return;
            }

            leader = 0;
            const match = ROUTE.exec(window.location.pathname);

            if (event.key === 'p') {
                event.preventDefault();
                router.visit(projectsIndex().url);
            } else if (event.key === 's') {
                event.preventDefault();
                router.visit(serversIndex().url);
            } else if (event.key === 'l' && match?.[1] && match[2]) {
                event.preventDefault();
                router.visit(projectLogs([match[1], match[2]]).url);
            } else if (event.key === 'd' && match?.[1]) {
                event.preventDefault();
                router.visit(projectDeployments(match[1]).url);
            } else if (event.key === 'v') {
                event.preventDefault();

                if (match?.[1] && match[2] && match[3]) {
                    router.visit(
                        serviceSection({
                            project: match[1],
                            environment: match[2],
                            service: match[3],
                            section: 'variables',
                        }).url,
                    );
                } else {
                    openCommandPalette('Variables');
                }
            }
        };

        window.addEventListener(OPEN_EVENT, onOpen);
        document.addEventListener('keydown', onKey);

        return () => {
            window.removeEventListener(OPEN_EVENT, onOpen);
            document.removeEventListener('keydown', onKey);
        };
    }, []);

    useEffect(() => {
        if (open) {
            setRecent(readRecent());
        } else {
            setConfirm(null);
        }
    }, [open]);

    const go = (url: string, item?: RecentItem) => {
        if (item) {
            addRecent(item);
        }

        setOpen(false);
        router.visit(url);
    };

    const ask = (label: string, run: () => void) => {
        setConfirm({ label, run });
        setSearch('');
    };

    const currentProjectEnvironments = useMemo(
        () =>
            (data?.environments ?? []).filter(
                (item) =>
                    item.projectId === context.project &&
                    item.environmentId !== context.environment,
            ),
        [data, context.project, context.environment],
    );

    const services = data?.services ?? [];
    // Without a query: only the open service's actions; searching: every
    // service of this project (or the team, outside a project).
    const searching = search.trim() !== '';
    const contextServices = searching
        ? context.project
            ? services.filter((item) => item.projectId === context.project)
            : services
        : services.filter((item) => item.id === context.service);
    const environmentFor = (item: Item) =>
        item.projectId === context.project && context.environment
            ? context.environment
            : item.environmentId;

    const urlFor = (item: Item): string | null => {
        switch (item.kind) {
            case 'project':
            case 'environment':
                return item.projectId && item.environmentId
                    ? canvas([item.projectId, item.environmentId]).url
                    : null;
            case 'service': {
                const environment = environmentFor(item);

                return item.projectId && environment
                    ? servicePanel({
                          project: item.projectId,
                          environment,
                          service: item.id,
                      }).url
                    : null;
            }
            case 'deployment':
                return item.projectId && item.environmentId
                    ? deploymentShow({
                          project: item.projectId,
                          environment: item.environmentId,
                          deployment: item.id,
                      }).url
                    : null;
            case 'server':
                return serverShow(item.id).url;
            default:
                return null;
        }
    };

    const itemRow = (item: Item, icon: ReactNode, prefix = '') => {
        const url = urlFor(item);

        if (url === null) {
            return null;
        }

        return (
            <CommandItem
                key={`${prefix}${item.kind}-${item.id}`}
                value={`${prefix}${item.kind} ${item.label} ${item.detail ?? ''} ${item.id}`}
                onSelect={() =>
                    go(url, {
                        kind: item.kind,
                        id: item.id,
                        label: item.label,
                        detail: item.detail ?? null,
                        url,
                    })
                }
            >
                {icon}
                <span className="truncate">{item.label}</span>
                {item.detail && (
                    <span className="truncate type-small text-muted-foreground">
                        {item.detail}
                    </span>
                )}
                {item.status && (
                    <PaletteStatus kind={item.kind} status={item.status} />
                )}
            </CommandItem>
        );
    };

    return (
        <CommandDialog
            open={open}
            onOpenChange={setOpen}
            title="Command palette"
            description="Jump to a page or run an action"
        >
            <CommandInput
                value={search}
                onValueChange={setSearch}
                placeholder={
                    confirm
                        ? 'Press Enter to confirm, Escape to cancel'
                        : 'Search projects, services, deployments, actions…'
                }
            />
            <CommandList>
                <CommandEmpty>Nothing found.</CommandEmpty>

                {confirm ? (
                    <CommandGroup heading="Confirm">
                        <CommandItem
                            value={`confirm ${confirm.label}`}
                            onSelect={() => {
                                confirm.run();
                                setOpen(false);
                            }}
                        >
                            <Rocket aria-hidden="true" />
                            {confirm.label}
                            <CommandShortcut>↵</CommandShortcut>
                        </CommandItem>
                        <CommandItem
                            value="cancel"
                            onSelect={() => setConfirm(null)}
                        >
                            <Undo2 aria-hidden="true" />
                            Cancel
                        </CommandItem>
                    </CommandGroup>
                ) : (
                    <>
                        {recent.length > 0 && search === '' && (
                            <CommandGroup heading="Recent">
                                {recent.map((item) => (
                                    <CommandItem
                                        key={`recent-${item.kind}-${item.id}`}
                                        value={`recent ${item.label} ${item.id}`}
                                        onSelect={() => go(item.url, item)}
                                    >
                                        <History aria-hidden="true" />
                                        <span className="truncate">
                                            {item.label}
                                        </span>
                                        {item.detail && (
                                            <span className="truncate type-small text-muted-foreground">
                                                {item.detail}
                                            </span>
                                        )}
                                        <CommandShortcut>
                                            {item.kind}
                                        </CommandShortcut>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        )}

                        <CommandGroup heading="Actions">
                            {context.deployment && (
                                <>
                                    {context.deployment.canRollback && (
                                        <CommandItem
                                            value="roll back to this deployment"
                                            onSelect={() => {
                                                const deployment =
                                                    context.deployment;

                                                if (deployment) {
                                                    ask(
                                                        `Roll back ${deployment.serviceName} to this deployment`,
                                                        () =>
                                                            router.post(
                                                                rollback({
                                                                    project:
                                                                        deployment.projectId,
                                                                    environment:
                                                                        deployment.environmentId,
                                                                    deployment:
                                                                        deployment.id,
                                                                }).url,
                                                            ),
                                                    );
                                                }
                                            }}
                                        >
                                            <RotateCcw aria-hidden="true" />
                                            Roll back to this deployment
                                        </CommandItem>
                                    )}
                                </>
                            )}
                            {data?.canDeploy &&
                                contextServices.map((item) => {
                                    const environment = environmentFor(item);

                                    if (!item.projectId || !environment) {
                                        return null;
                                    }

                                    const project = item.projectId;

                                    return (
                                        <CommandItem
                                            key={`redeploy-${item.id}`}
                                            value={`redeploy ${item.label} ${item.detail ?? ''}`}
                                            onSelect={() =>
                                                ask(
                                                    `Redeploy ${item.label}`,
                                                    () =>
                                                        router.post(
                                                            redeployService({
                                                                project,
                                                                environment,
                                                                service:
                                                                    item.id,
                                                            }).url,
                                                        ),
                                                )
                                            }
                                        >
                                            <Rocket aria-hidden="true" />
                                            Redeploy {item.label}
                                        </CommandItem>
                                    );
                                })}
                            {contextServices.map((item) => {
                                const environment = environmentFor(item);

                                if (!item.projectId || !environment) {
                                    return null;
                                }

                                const project = item.projectId;

                                return [
                                    <CommandItem
                                        key={`logs-${item.id}`}
                                        value={`open logs ${item.label} deployments`}
                                        onSelect={() =>
                                            go(
                                                servicePanel({
                                                    project,
                                                    environment,
                                                    service: item.id,
                                                    tab: 'deployments',
                                                }).url,
                                            )
                                        }
                                    >
                                        <ScrollText aria-hidden="true" />
                                        Open logs of {item.label}
                                    </CommandItem>,
                                    data?.canWriteVariables ? (
                                        <CommandItem
                                            key={`variable-${item.id}`}
                                            value={`add variable ${item.label} variables env`}
                                            onSelect={() =>
                                                go(
                                                    `${serviceSection({ project, environment, service: item.id, section: 'variables' }).url}?add=variable`,
                                                )
                                            }
                                        >
                                            <SquarePlus aria-hidden="true" />
                                            Add variable to {item.label}
                                        </CommandItem>
                                    ) : null,
                                ];
                            })}
                            {currentProjectEnvironments.map((item) => (
                                <CommandItem
                                    key={`switch-${item.id}`}
                                    value={`switch environment ${item.label}`}
                                    onSelect={() =>
                                        item.projectId &&
                                        go(
                                            canvas([item.projectId, item.id])
                                                .url,
                                        )
                                    }
                                >
                                    <Layers aria-hidden="true" />
                                    Switch to {item.label}
                                </CommandItem>
                            ))}
                        </CommandGroup>

                        <CommandSeparator />

                        <CommandGroup heading="Navigate">
                            <CommandItem
                                value="go projects"
                                onSelect={() => go(projectsIndex().url)}
                            >
                                <FolderKanban aria-hidden="true" />
                                Projects
                                <CommandShortcut>g p</CommandShortcut>
                            </CommandItem>
                            {context.project && context.environment && (
                                <CommandItem
                                    value="go logs of this environment"
                                    onSelect={() =>
                                        context.project &&
                                        context.environment &&
                                        go(
                                            projectLogs([
                                                context.project,
                                                context.environment,
                                            ]).url,
                                        )
                                    }
                                >
                                    <ScrollText aria-hidden="true" />
                                    Logs
                                    <CommandShortcut>g l</CommandShortcut>
                                </CommandItem>
                            )}
                            {context.project && (
                                <CommandItem
                                    value="go deployments of this project"
                                    onSelect={() =>
                                        context.project &&
                                        go(
                                            projectDeployments(context.project)
                                                .url,
                                        )
                                    }
                                >
                                    <Rocket aria-hidden="true" />
                                    Deployments
                                    <CommandShortcut>g d</CommandShortcut>
                                </CommandItem>
                            )}
                            <CommandItem
                                value="go servers"
                                onSelect={() => go(serversIndex().url)}
                            >
                                <Server aria-hidden="true" />
                                Servers
                                <CommandShortcut>g s</CommandShortcut>
                            </CommandItem>
                            <CommandItem
                                value="go activity"
                                onSelect={() => go(activityIndex().url)}
                            >
                                <Activity aria-hidden="true" />
                                Activity
                            </CommandItem>
                            <CommandItem
                                value="go team settings"
                                onSelect={() => go(teamSettings().url)}
                            >
                                <Settings aria-hidden="true" />
                                Team settings
                            </CommandItem>
                        </CommandGroup>

                        {data && (
                            <>
                                <CommandGroup heading="Projects">
                                    {data.projects.map((item) =>
                                        itemRow(
                                            item,
                                            item.iconUrl ? (
                                                <ProjectIcon
                                                    name={item.label}
                                                    iconUrl={item.iconUrl}
                                                    className="size-4 rounded-[4px] p-0"
                                                />
                                            ) : (
                                                <FolderKanban aria-hidden="true" />
                                            ),
                                        ),
                                    )}
                                </CommandGroup>
                                <CommandGroup heading="Services">
                                    {(searching ? services : []).map((item) =>
                                        itemRow(
                                            item,
                                            <Boxes aria-hidden="true" />,
                                        ),
                                    )}
                                </CommandGroup>
                                <CommandGroup heading="Deployments">
                                    {data.deployments
                                        .slice(0, searching ? undefined : 5)
                                        .map((item) =>
                                            itemRow(
                                                item,
                                                <Rocket aria-hidden="true" />,
                                            ),
                                        )}
                                </CommandGroup>
                                <CommandGroup heading="Servers">
                                    {(searching ? data.servers : []).map(
                                        (item) =>
                                            itemRow(
                                                item,
                                                <Server aria-hidden="true" />,
                                            ),
                                    )}
                                </CommandGroup>
                            </>
                        )}
                    </>
                )}
            </CommandList>
            <div className="flex items-center gap-4 border-t px-3 py-2 type-mono-small text-muted-foreground">
                <span>↑↓ move</span>
                <span>↵ open</span>
                <span>esc close</span>
                <span className="ml-auto">⌘K</span>
            </div>
        </CommandDialog>
    );
}
