import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { SettingsShell } from '@/components/settings/settings-shell';
import { allPages } from '@/components/settings/types';
import { ManagedByFile } from '@/features/config-as-code/managed-by-file';
import services from '@/routes/projects/services';
import { AdvancedSection } from './advanced-section';
import { BuildSection } from './build-section';
import { describeCron, parseCron } from './cron';
import { DangerZone } from './danger-zone';
import { DeploySection } from './deploy-section';
import { useDirtySections } from './dirty';
import { DomainsSection } from './domains-section';
import { GeneralSection } from './general-section';
import { FirewallSection } from './firewall-section';
import { NetworkingSection } from './networking-section';
import { ScalingSection } from './scaling-section';
import { ScheduleSection } from './schedule-section';
import { SettingsPageProvider } from './section';
import { DEFAULT_SECTION, settingsAreas } from './sections';
import type { SectionId } from './sections';
import type { SettingsContext } from './settings-context';
import { settingsIndex } from './settings-index';
import { SourceSection } from './source-section';
import { VolumesSection } from './volumes-section';

export type Navigate = (section: SectionId | null) => void;

function pageFor(
    id: SectionId,
    context: SettingsContext,
    navigate: Navigate,
): ReactNode {
    switch (id) {
        case 'general':
            return <GeneralSection context={context} navigate={navigate} />;
        case 'source':
            return <SourceSection context={context} />;
        case 'build':
            return <BuildSection context={context} />;
        case 'deploy':
            return <DeploySection context={context} />;
        case 'schedule':
            return <ScheduleSection context={context} />;
        case 'domains':
            return <DomainsSection context={context} />;
        case 'networking':
            return <NetworkingSection context={context} navigate={navigate} />;
        case 'firewall':
            return <FirewallSection context={context} />;
        case 'scaling':
            return <ScalingSection context={context} />;
        case 'volumes':
            return <VolumesSection context={context} />;
        case 'advanced':
            return <AdvancedSection context={context} />;
        case 'danger':
            return <DangerZone context={context} />;
    }
}

function plural(count: number, word: string): string {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}

function scheduleSummary(
    schedule: App.Data.Services.ScheduleSettings | null,
): string {
    if (!schedule) {
        return 'Runs continuously';
    }

    const parsed = parseCron(schedule.cron);

    return 'cron' in parsed
        ? `${describeCron(parsed.cron)} (${schedule.timeZone.replace(/_/g, ' ')})`
        : schedule.cron;
}

/** One quiet line per page for the phone drill-down list. */
function summaries(
    context: SettingsContext,
): Partial<Record<SectionId, string>> {
    const { settings, node } = context;
    const custom = settings.domains.filter(
        (domain) => domain.kind === 'custom',
    );

    return {
        general: node.name,
        domains:
            settings.domains.length === 0
                ? 'No domains yet'
                : (custom[0]?.hostname ?? settings.domains[0].hostname),
        scaling:
            node.kind === 'database'
                ? 'One replica'
                : `${plural(settings.scaling.replicas, 'replica')}, ${settings.scaling.cpus} CPU and ${settings.scaling.memoryMb} MB${settings.scaling.replicas > 1 ? ' each' : ''}`,
        volumes:
            settings.volumes.length === 0
                ? 'No volumes'
                : settings.volumes.map((volume) => volume.mountPath).join(', '),
        schedule: scheduleSummary(settings.deploy.schedule),
        advanced:
            node.kind === 'database'
                ? 'Ids, image and replicas for debugging'
                : 'Entrypoint, capabilities and ids',
        danger: settings.enabled ? 'Disable or remove' : 'Disabled',
    };
}

/**
 * Service panel → Settings (docs/08 §16): a sidebar listing every page in
 * titled groups (Build & deploy, Network, Resources) beside one page at a
 * time. The page is in the URL
 * (`/s/{service}/settings/{section}`, client-side so switching is instant
 * and Back works). Pages stay mounted once visited, so unsaved edits
 * survive switching (the sidebar marks them). Narrow panels and phones get
 * a drill-down list instead of the sidebar.
 */
export function SettingsTab({
    context,
    section,
}: {
    context: SettingsContext;
    section: string | null;
}) {
    const areas = settingsAreas(context);
    const pages = allPages(areas);
    const requested = pages.find((page) => page.id === section)?.id ?? null;
    const current: SectionId = requested ?? DEFAULT_SECTION;
    const [visited, setVisited] = useState<Set<SectionId>>(
        () => new Set([current]),
    );
    const [project, environment, service] = context.route;
    const root = useRef<HTMLDivElement>(null);
    const dirty = useDirtySections();
    const lines = summaries(context);

    useEffect(() => {
        setVisited((previous) =>
            previous.has(current) ? previous : new Set([...previous, current]),
        );
    }, [current]);

    const hrefFor = useCallback(
        (id: SectionId | null) =>
            id === null
                ? services.show({
                      project,
                      environment,
                      service,
                      tab: 'settings',
                  }).url
                : services.section({
                      project,
                      environment,
                      service,
                      section: id,
                  }).url,
        [project, environment, service],
    );

    const navigate: Navigate = useCallback(
        (id) => {
            router.push({
                url: hrefFor(id),
                preserveScroll: true,
                preserveState: true,
                props: (props) => {
                    const panel = (
                        props as {
                            panel?: App.Data.Canvas.ServicePanelData | null;
                        }
                    ).panel;

                    return {
                        ...props,
                        panel: panel ? { ...panel, section: id } : panel,
                    };
                },
            });
            root.current?.closest('[data-panel-scroll]')?.scrollTo({ top: 0 });
        },
        [hrefFor],
    );

    return (
        <div ref={root}>
            <SettingsShell
                label="Settings"
                groups={areas.map((area) => ({
                    ...area,
                    pages: area.pages.map((page) => ({
                        ...page,
                        summary: lines[page.id],
                    })),
                }))}
                current={current}
                requested={requested}
                hrefFor={hrefFor}
                onNavigate={navigate}
                index={settingsIndex(context, areas)}
                onSelectOther={(result) => {
                    if (result.target === 'variables') {
                        router.visit(
                            services.show({
                                project,
                                environment,
                                service,
                                tab: 'variables',
                            }).url,
                            {
                                only: ['panel'],
                                preserveState: true,
                                preserveScroll: true,
                            },
                        );
                    }
                }}
                emptyHint="Try “domain”, “port” or “health check”."
                dirty={dirty}
                notice={
                    context.canUpdate
                        ? undefined
                        : 'You can view these settings. Changing them needs the developer role or higher.'
                }
            >
                {pages
                    .filter(
                        (page) => visited.has(page.id) || page.id === current,
                    )
                    .map((page) => (
                        <div
                            key={page.id}
                            data-settings-page={page.id}
                            hidden={page.id !== current}
                            className="motion-safe:animate-in motion-safe:duration-base motion-safe:ease-enter motion-safe:fade-in-0"
                        >
                            <SettingsPageProvider
                                id={page.id}
                                active={page.id === current}
                            >
                                <ManagedByFile
                                    managed={context.settings.configFile}
                                    page={page.id}
                                >
                                    {pageFor(page.id, context, navigate)}
                                </ManagedByFile>
                            </SettingsPageProvider>
                        </div>
                    ))}
            </SettingsShell>
        </div>
    );
}
