import { Link } from '@inertiajs/react';
import { ArrowUpRight, Maximize2, Minimize2, X } from 'lucide-react';
import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { AlertsChip } from '@/components/attention-chip';
import { StatusPill } from '@/components/status';
import { MetaDivider } from '@/components/patterns/meta-line';
import { Button } from '@/components/ui/button';
import { compactAge } from '@/features/canvas/format';
import { ServiceGlyph } from '@/features/canvas/service-glyph';
import {
    ServiceControls,
    ServiceControlsPlaceholder,
} from '@/features/service-panel/service-controls';
import { StagedChip } from '@/features/canvas/service-node';
import {
    failedDeployment,
    secondarySignals,
} from '@/features/service-panel/panel-signals';
import { DeploymentsTab } from '@/features/deployments/deployments-tab';
import { ServiceLogs } from '@/features/project-logs/service-logs';
import { ServiceStagedBar } from '@/features/staged-changes/service-staged-bar';
import { warmPages, whenIdle } from '@/lib/idle';
import { serviceAnalyticsHref } from '@/lib/links';
import { useLocationSearch } from '@/hooks/use-observe-scope';
import { prefersReducedMotion, sharedElement } from '@/lib/motion';
import { readScope, scopeQuery } from '@/lib/observe-scope';
import { cn } from '@/lib/utils';
import { show as deploymentShow } from '@/routes/deployments';
import services from '@/routes/projects/services';
import { index as securityRoute } from '@/routes/security';
import { show as findingRoute } from '@/routes/security/findings';

const EXPANDED_KEY = 'launchroom.panel.expanded';

// Heavy tabs load with their first use, not with the canvas: the console's
// terminal (xterm), the data browser and the settings pages. Settings is
// warmed once the panel is idle, so its first click doesn't wait and the
// download doesn't compete with the Deployments list.
const loadSettingsTab = () =>
    import('@/features/service-settings/settings-tab').then((module) => ({
        default: module.SettingsTab,
    }));
const SettingsTab = lazy(loadSettingsTab);
const ConsoleTab = lazy(() =>
    import('@/features/sessions/console-tab').then((module) => ({
        default: module.ConsoleTab,
    })),
);
const DataTab = lazy(() =>
    import('@/features/sessions/data-tab').then((module) => ({
        default: module.DataTab,
    })),
);

type Tab = {
    id: string;
    label: string;
    /** Opens another page instead of a panel tab. */
    href?: string;
};

/**
 * The route-backed service panel over the canvas (docs/08 §1, direction
 * §9.5): a floating sheet 12 px in from the canvas edges so the
 * canvas visibly continues behind it. Header with the logo (it morphs from
 * the node, shared element `svc-<id>`), name, status and one meta line;
 * tabs Deployments, Logs and Settings (+ Console and Data with their
 * permission; Variables and Backups are settings pages), then Analytics,
 * a link to the project's Analytics page filtered to the service (D75).
 * Every tab has a URL (`/p/{project}/{env}/s/{service}/{tab}`); expanded it
 * covers the canvas.
 *
 * `pending`: drawn on click from the canvas node while the panel's request
 * runs (docs/08 §2). The header, the tabs and the client-loaded tabs show
 * at once; the header actions (their place is held with
 * `reserveControls`), Settings, Console and Data wait for the answer.
 */
export function ServicePanel({
    project,
    environment,
    node,
    panel,
    onClose,
    onSelectTab,
    pending = false,
    reserveControls = false,
}: {
    project: string;
    environment: string;
    node: App.Data.Canvas.CanvasNodeData;
    panel: App.Data.Canvas.ServicePanelData;
    onClose: () => void;
    /** Switches tabs (the canvas decides: client-side or a visit) to `href`; without it, tabs are links. */
    onSelectTab?: (tab: string, href?: string) => void;
    pending?: boolean;
    reserveControls?: boolean;
}) {
    const [pinned, setPinned] = useState(() => {
        try {
            return window.localStorage.getItem(EXPANDED_KEY) === '1';
        } catch {
            return false;
        }
    });
    // Settings opens full width (its sidebar needs the room) and collapses
    // again on the other tabs; the toggle narrows it for this visit only.
    const settingsTab = panel.tab === 'settings';
    const [settingsNarrow, setSettingsNarrow] = useState(false);
    const expanded = settingsTab ? !settingsNarrow : pinned;
    const toggleExpanded = () =>
        settingsTab
            ? setSettingsNarrow((value) => !value)
            : setPinned((value) => !value);
    const ref = useRef<HTMLElement>(null);
    const route: [string, string, string] = [project, environment, node.id];
    const scope = readScope(useLocationSearch());
    const tabs: Tab[] = [
        { id: 'deployments', label: 'Deployments' },
        { id: 'logs', label: 'Logs' },
        ...(panel.canConsole ? [{ id: 'console', label: 'Console' }] : []),
        ...(panel.canData ? [{ id: 'data', label: 'Data' }] : []),
        { id: 'settings', label: 'Settings' },
        {
            id: 'analytics',
            label: 'Analytics',
            // Traffic for a service with a domain, else its resources.
            href: serviceAnalyticsHref({
                project,
                environment,
                service: node.id,
                hasDomain: node.domain !== null,
                range: scope.range,
            }),
        },
    ];
    useEffect(() => {
        const cancelWarm = whenIdle(() => void loadSettingsTab());
        // The next click is often a deployment.
        const cancelPages = warmPages(['projects/deployments/show']);

        return () => {
            cancelWarm();
            cancelPages();
        };
    }, []);

    useEffect(() => {
        try {
            window.localStorage.setItem(EXPANDED_KEY, pinned ? '1' : '0');
        } catch {
            // Private mode: the panel just forgets.
        }
    }, [pinned]);

    useEffect(() => {
        const element = ref.current;
        const onKey = (event: KeyboardEvent) => {
            if (
                event.key === 'Escape' &&
                !event.defaultPrevented &&
                element?.contains(document.activeElement) &&
                !document.querySelector('[role="dialog"][data-state="open"]')
            ) {
                onClose();
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <aside
            ref={ref}
            aria-label={`${node.name} panel`}
            className={cn(
                'absolute inset-0 z-20 flex flex-col overflow-hidden bg-card text-card-foreground md:inset-y-3 md:right-3 md:left-auto md:rounded-2xl md:border md:shadow-float',
                'motion-safe:animate-in motion-safe:duration-slow motion-safe:ease-enter motion-safe:fade-in-0 motion-safe:slide-in-from-right-8',
                'md:transition-[width] md:duration-slow md:ease-enter',
                expanded ? 'md:w-[calc(100%-1.5rem)]' : 'md:w-[min(680px,62%)]',
            )}
        >
            <header className="@container/panel-header flex shrink-0 flex-col gap-4 border-b px-5 pt-5 md:px-6">
                {/* One grid: the logo, the title row with the actions and
                    the window buttons, then the meta line across the whole
                    width under them. On a narrow header the actions move to
                    their own row under the meta line. */}
                <div className="grid grid-cols-[auto_minmax(0,1fr)_auto_auto] items-start gap-x-4">
                    <ServiceGlyph
                        logo={node.logo}
                        size="lg"
                        className="row-span-2 row-start-1"
                        style={sharedElement('svc', node.id)}
                    />
                    <div className="col-span-2 col-start-2 row-start-1 flex min-h-8 min-w-0 flex-wrap items-center gap-x-2.5 gap-y-1 @[560px]/panel-header:col-span-1">
                        <h2 className="truncate text-[20px] leading-7 font-semibold tracking-[-0.01em]">
                            {node.name}
                        </h2>
                        <PanelStatus
                            project={project}
                            environment={environment}
                            status={node.status}
                        />
                        {node.staged && <StagedChip effect={node.staged} />}
                        <AlertsChip
                            alerts={secondarySignals(
                                node.status.label,
                                node.openAlerts,
                                panel.actionNeeded > 0
                                    ? {
                                          count: panel.actionNeeded,
                                          href: actionNeededHref(panel),
                                      }
                                    : null,
                            )}
                        />
                    </div>
                    <PanelMeta
                        className="col-span-3 col-start-2 row-start-2 mt-1"
                        project={project}
                        environment={environment}
                        node={node}
                        origin={panel.origin}
                    />
                    {panel.controls?.canControl ? (
                        <div className="col-span-2 col-start-2 row-start-3 mt-2 @[560px]/panel-header:col-span-1 @[560px]/panel-header:col-start-3 @[560px]/panel-header:row-start-1 @[560px]/panel-header:mt-0">
                            <ServiceControls
                                project={project}
                                environment={environment}
                                service={node.id}
                                name={node.name}
                                controls={panel.controls}
                            />
                        </div>
                    ) : (
                        reserveControls &&
                        !panel.controls && (
                            <div className="col-span-2 col-start-2 row-start-3 mt-2 @[560px]/panel-header:col-span-1 @[560px]/panel-header:col-start-3 @[560px]/panel-header:row-start-1 @[560px]/panel-header:mt-0">
                                <ServiceControlsPlaceholder node={node} />
                            </div>
                        )
                    )}
                    <div className="col-start-4 row-start-1 -mr-2 flex h-8 shrink-0 items-center">
                        {(panel.controls
                            ? panel.controls.canControl
                            : reserveControls) && (
                            <span
                                aria-hidden="true"
                                className="mr-1 hidden h-5 w-px bg-border @[560px]/panel-header:block"
                            />
                        )}
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            className="hidden md:inline-flex"
                            aria-label={
                                expanded ? 'Collapse panel' : 'Expand panel'
                            }
                            aria-pressed={expanded}
                            onClick={toggleExpanded}
                        >
                            {expanded ? <Minimize2 /> : <Maximize2 />}
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Close panel"
                            onClick={onClose}
                        >
                            <X />
                        </Button>
                    </div>
                </div>

                {node.removed && (
                    <p className="rounded-lg bg-signal-soft px-3 py-2 type-small text-signal">
                        Removal staged: {node.name} keeps running until the next
                        deploy. Discard the change to keep it.
                    </p>
                )}

                <nav
                    aria-label={`${node.name} sections`}
                    className="-mb-px flex [scrollbar-width:none] gap-5 overflow-x-auto [mask-image:linear-gradient(to_right,black_85%,transparent)] pr-6 sm:[mask-image:none] sm:pr-0"
                >
                    {tabs.map((tab) =>
                        tab.href ? (
                            <Link
                                key={tab.id}
                                href={tab.href}
                                className="inline-flex shrink-0 items-center gap-0.5 border-b-2 border-transparent pb-2.5 type-label text-muted-foreground transition-colors duration-fast hover:text-foreground focus-visible:text-foreground focus-visible:outline-none"
                            >
                                {tab.label}
                                <ArrowUpRight
                                    aria-hidden="true"
                                    className="size-3"
                                />
                            </Link>
                        ) : (
                            <Link
                                key={tab.id}
                                href={services.show(
                                    {
                                        project,
                                        environment,
                                        service: node.id,
                                        tab: tab.id,
                                    },
                                    // Logs keeps the range (docs/08 §1).
                                    tab.id === 'logs'
                                        ? {
                                              query: scopeQuery({
                                                  range: scope.range,
                                              }),
                                          }
                                        : undefined,
                                )}
                                only={['panel']}
                                preserveState
                                preserveScroll
                                onClick={(event) => {
                                    if (
                                        !onSelectTab ||
                                        event.button !== 0 ||
                                        event.metaKey ||
                                        event.ctrlKey ||
                                        event.shiftKey ||
                                        event.altKey
                                    ) {
                                        return;
                                    }

                                    event.preventDefault();
                                    onSelectTab(
                                        tab.id,
                                        event.currentTarget.getAttribute(
                                            'href',
                                        ) ?? undefined,
                                    );
                                }}
                                aria-current={
                                    panel.tab === tab.id ? 'page' : undefined
                                }
                                className={cn(
                                    'shrink-0 border-b-2 pb-2.5 type-label transition-colors duration-fast focus-visible:text-foreground focus-visible:outline-none',
                                    panel.tab === tab.id
                                        ? 'border-foreground text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {tab.label}
                            </Link>
                        ),
                    )}
                </nav>
            </header>

            {/* Positioned: inline StagedBar/UnsavedBar float over the panel. */}
            <div className="relative flex min-h-0 flex-1 flex-col">
                <div
                    data-panel-scroll
                    className="min-h-0 flex-1 overflow-y-auto"
                >
                    <div
                        key={panel.tab}
                        className={cn(
                            'mx-auto flex w-full flex-col px-5 pt-6 pb-24 md:px-6',
                            'motion-safe:animate-in motion-safe:duration-base motion-safe:fade-in-0',
                            expanded
                                ? settingsTab
                                    ? 'max-w-[1040px]'
                                    : 'max-w-[960px]'
                                : 'max-w-none',
                        )}
                    >
                        <Suspense fallback={null}>
                            {panel.tab === 'deployments' && (
                                <DeploymentsTab
                                    project={project}
                                    environment={environment}
                                    service={node.id}
                                    database={node.kind === 'database'}
                                    controls={panel.controls}
                                    logo={node.logo}
                                />
                            )}
                            {panel.tab === 'console' &&
                                panel.canConsole &&
                                !pending && (
                                    <ConsoleTab
                                        key={`${node.id}-${environment}`}
                                        project={project}
                                        environment={environment}
                                        service={node.id}
                                        dataHint={panel.canData}
                                    />
                                )}
                            {panel.tab === 'data' &&
                                panel.canData &&
                                !pending && (
                                    <DataTab
                                        key={`${node.id}-${environment}`}
                                        project={project}
                                        environment={environment}
                                        service={node.id}
                                    />
                                )}
                            {panel.tab === 'logs' && (
                                <ServiceLogs
                                    project={project}
                                    environment={environment}
                                    service={node.id}
                                    slug={node.slug}
                                    deployment={node.status.deploymentId}
                                    running={
                                        node.status.state !== 'idle' &&
                                        node.status.label !== 'Failed'
                                    }
                                />
                            )}
                            {panel.tab === 'settings' && panel.settings && (
                                <SettingsTab
                                    key={`${node.id}-${environment}`}
                                    section={panel.section}
                                    context={{
                                        route,
                                        node,
                                        settings: panel.settings,
                                        canUpdate: panel.canUpdate,
                                        canDelete: panel.canDelete,
                                    }}
                                />
                            )}
                        </Suspense>
                    </div>
                </div>
                {panel.tab === 'settings' && (
                    <ServiceStagedBar
                        project={project}
                        environment={environment}
                        service={node.id}
                    />
                )}
            </div>
        </aside>
    );
}

/** Visit options for opening/closing the panel: the logo morphs (design-system §8). */
export function panelVisitOptions() {
    return {
        only: ['panel'],
        preserveState: true,
        preserveScroll: true,
        viewTransition: !prefersReducedMotion(),
    };
}

/** Where "Action needed" leads (docs/22 §9.1): the finding, or the Security page for this service when there are several. */
function actionNeededHref(panel: App.Data.Canvas.ServicePanelData): string {
    return panel.actionNeededFinding
        ? findingRoute(panel.actionNeededFinding).url
        : securityRoute({ query: { service: panel.serviceId } }).url;
}

/**
 * The one status of the title row. A failed deploy links to the
 * deployment that says why.
 */
function PanelStatus({
    project,
    environment,
    status,
}: {
    project: string;
    environment: string;
    status: App.Data.Canvas.NodeStatusData;
}) {
    const pill = (
        <StatusPill
            state={status.state}
            label={status.label}
            detail={status.since ? compactAge(status.since) : undefined}
        />
    );
    const deployment = failedDeployment(status);

    if (!deployment) {
        return pill;
    }

    return (
        <Link
            href={deploymentShow({ project, environment, deployment }).url}
            title="See why it failed"
            className="inline-flex max-w-full min-w-0 rounded-full underline-offset-2 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
        >
            {pill}
        </Link>
    );
}

/**
 * The header's one meta line: where the service comes from (the
 * repository at its branch, the image, the engine, or the app a worker
 * belongs to), then where it answers (the domain, else the private
 * address). Both cut with an ellipsis and show in full on hover.
 */
function PanelMeta({
    project,
    environment,
    node,
    origin,
    className,
}: {
    className?: string;
    project: string;
    environment: string;
    node: App.Data.Canvas.CanvasNodeData;
    /** Null while the panel's request runs: the node's subtitle stands in. */
    origin: App.Data.Canvas.ServiceOriginData | null;
}) {
    const label = origin?.label ?? node.subtitle;
    const mono = origin?.kind === 'image';
    const where = origin?.host
        ? `${label} on ${origin.host}${origin.branch ? `, branch ${origin.branch}` : ''}`
        : `${label}${origin?.branch ? `, branch ${origin.branch}` : ''}`;
    const linkClass =
        'inline-flex max-w-full min-w-0 items-center gap-0.5 rounded-sm underline-offset-4 outline-none hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring';

    const source = origin?.href ? (
        <a
            href={origin.href}
            target="_blank"
            rel="noreferrer"
            title={`Open ${where}`}
            className={linkClass}
        >
            <span className={cn('truncate', mono && 'type-mono-small')}>
                {label}
            </span>
            <ArrowUpRight aria-hidden="true" className="size-3 shrink-0" />
        </a>
    ) : origin?.kind === 'process' && node.parentId ? (
        <Link
            href={services.show({
                project,
                environment,
                service: node.parentId,
                tab: 'deployments',
            })}
            only={['panel']}
            preserveState
            preserveScroll
            title={label}
            className={linkClass}
        >
            <span className="truncate">{label}</span>
        </Link>
    ) : (
        <span
            title={where}
            className={cn(
                'max-w-full min-w-0 truncate',
                mono && 'type-mono-small',
            )}
        >
            {label}
        </span>
    );

    const address = node.domain ? (
        <a
            href={`https://${node.domain}`}
            target="_blank"
            rel="noreferrer"
            title={`https://${node.domain}`}
            className={cn(linkClass, 'type-mono-small')}
        >
            <span className="truncate">{node.domain}</span>
            <ArrowUpRight aria-hidden="true" className="size-3 shrink-0" />
        </a>
    ) : node.privateAddress ? (
        <span
            title="Private address: the other services of this environment reach it here"
            className="max-w-full min-w-0 truncate type-mono-small select-all"
        >
            {node.privateAddress}
        </span>
    ) : null;

    return (
        <p
            className={cn(
                // Stacked on a narrow header, one line with a hairline on a wide one.
                'flex min-w-0 flex-col items-start gap-0.5 type-small text-muted-foreground @[560px]/panel-header:h-5 @[560px]/panel-header:flex-row @[560px]/panel-header:items-center @[560px]/panel-header:gap-2',
                className,
            )}
        >
            {source}
            {address && (
                <>
                    <MetaDivider className="hidden @[560px]/panel-header:inline-block" />
                    {address}
                </>
            )}
        </p>
    );
}
