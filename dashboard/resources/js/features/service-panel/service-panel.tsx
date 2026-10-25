import { Link } from '@inertiajs/react';
import { ArrowUpRight, CircleX, Maximize2, Minimize2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { StatusPill } from '@/components/status';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { BackupsTab } from '@/features/backups/backups-tab';
import { compactAge } from '@/features/canvas/format';
import { ServiceGlyph } from '@/features/canvas/service-glyph';
import { ConsoleTab } from '@/features/sessions/console-tab';
import { DataTab } from '@/features/sessions/data-tab';
import { placementLine, StagedChip } from '@/features/canvas/service-node';
import { DeploymentsTab } from '@/features/deployments/deployments-tab';
import { ServiceMetrics } from '@/features/metrics/service-metrics';
import { ServiceLogs } from '@/features/project-logs/service-logs';
import { SettingsTab } from '@/features/service-settings/settings-tab';
import { ServiceStagedBar } from '@/features/staged-changes/service-staged-bar';
import { VariablesTab } from '@/features/variables/variables-tab';
import { prefersReducedMotion, sharedElement } from '@/lib/motion';
import { cn } from '@/lib/utils';
import services from '@/routes/projects/services';
import { index as securityRoute } from '@/routes/security';
import { show as findingRoute } from '@/routes/security/findings';

const EXPANDED_KEY = 'launchroom.panel.expanded';

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
 * tabs Deployments · Variables · Metrics · Logs · Settings (+ Backups for
 * stateful services). Every tab has a URL
 * (`/p/{project}/{env}/s/{service}/{tab}`); expanded it covers the canvas.
 */
export function ServicePanel({
    project,
    environment,
    node,
    panel,
    onClose,
}: {
    project: string;
    environment: string;
    node: App.Data.Canvas.CanvasNodeData;
    panel: App.Data.Canvas.ServicePanelData;
    onClose: () => void;
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
    // Backups: databases and services with volumes (docs/08 §1, docs/14 §2).
    const hasBackups = node.kind === 'database' || node.volume !== null;
    const tabs: Tab[] = [
        { id: 'deployments', label: 'Deployments' },
        { id: 'variables', label: 'Variables' },
        { id: 'metrics', label: 'Metrics' },
        { id: 'logs', label: 'Logs' },
        ...(panel.canConsole ? [{ id: 'console', label: 'Console' }] : []),
        ...(panel.canData ? [{ id: 'data', label: 'Data' }] : []),
        { id: 'settings', label: 'Settings' },
        ...(hasBackups ? [{ id: 'backups', label: 'Backups' }] : []),
    ];
    const placement = placementLine(node);
    const meta = [node.subtitle, placement].filter((part): part is string =>
        Boolean(part),
    );

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
            <header className="flex shrink-0 flex-col gap-4 border-b px-5 pt-5 md:px-6">
                <div className="flex items-start gap-4">
                    <ServiceGlyph
                        logo={node.logo}
                        size="lg"
                        style={sharedElement('svc', node.id)}
                    />
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <div className="flex min-w-0 flex-wrap items-center gap-x-2.5 gap-y-1">
                            <h2 className="truncate text-[20px] leading-7 font-semibold tracking-[-0.01em]">
                                {node.name}
                            </h2>
                            <StatusPill
                                state={node.status.state}
                                label={node.status.label}
                                detail={
                                    node.status.since
                                        ? compactAge(node.status.since)
                                        : undefined
                                }
                            />
                            {node.staged && <StagedChip effect={node.staged} />}
                            {panel.actionNeeded > 0 && (
                                <ActionNeededChip panel={panel} />
                            )}
                        </div>
                        <p className="flex min-w-0 flex-wrap items-center gap-x-2 type-small text-muted-foreground">
                            {meta.map((part) => (
                                <span key={part} className="truncate">
                                    {part}
                                </span>
                            ))}
                            {node.domain && (
                                <a
                                    href={`https://${node.domain}`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex min-w-0 items-center gap-0.5 truncate type-mono-small hover:text-foreground"
                                >
                                    {node.domain}
                                    <ArrowUpRight
                                        aria-hidden="true"
                                        className="size-3 shrink-0"
                                    />
                                </a>
                            )}
                        </p>
                    </div>
                    <div className="-mt-1 -mr-2 flex shrink-0 items-center">
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
                                href={services.show({
                                    project,
                                    environment,
                                    service: node.id,
                                    tab: tab.id,
                                })}
                                only={['panel']}
                                preserveState
                                preserveScroll
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
                        {panel.tab === 'deployments' && (
                            <DeploymentsTab
                                project={project}
                                environment={environment}
                                service={node.id}
                                database={node.kind === 'database'}
                            />
                        )}
                        {panel.tab === 'variables' && (
                            <VariablesTab
                                project={project}
                                environment={environment}
                                service={node.id}
                            />
                        )}
                        {panel.tab === 'metrics' && (
                            <ServiceMetrics
                                project={project}
                                environment={environment}
                                service={node.id}
                            />
                        )}
                        {panel.tab === 'console' && panel.canConsole && (
                            <ConsoleTab
                                key={`${node.id}-${environment}`}
                                project={project}
                                environment={environment}
                                service={node.id}
                                dataHint={panel.canData}
                            />
                        )}
                        {panel.tab === 'data' && panel.canData && (
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
                            />
                        )}
                        {panel.tab === 'backups' && hasBackups && (
                            <BackupsTab
                                project={project}
                                environment={environment}
                                service={node.id}
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
                    </div>
                </div>
                {(panel.tab === 'variables' || panel.tab === 'settings') && (
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

/**
 * "Action needed" in the header (docs/22 §9.1): opens the finding, or the
 * Security page for this service when there are several.
 */
function ActionNeededChip({
    panel,
}: {
    panel: App.Data.Canvas.ServicePanelData;
}) {
    const href = panel.actionNeededFinding
        ? findingRoute(panel.actionNeededFinding).url
        : securityRoute({ query: { service: panel.serviceId } }).url;

    return (
        <Badge variant="destructive" className="gap-1" asChild>
            <Link href={href}>
                <CircleX aria-hidden="true" className="size-3" />
                Action needed
                {panel.actionNeeded > 1 && (
                    <span className="tabular-nums">{panel.actionNeeded}</span>
                )}
            </Link>
        </Badge>
    );
}
