import {
    Boxes,
    CalendarClock,
    Container,
    Database,
    GitBranch,
    HardDrive,
    Layers,
    Link2,
    Network,
    Rocket,
    ShieldCheck,
    SlidersHorizontal,
    TriangleAlert,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type {
    SettingsNavGroup,
    SettingsNavPage,
} from '@/components/settings/types';
import type { SettingsContext } from './settings-context';

export type SectionId =
    | 'general'
    | 'source'
    | 'build'
    | 'deploy'
    | 'schedule'
    | 'domains'
    | 'networking'
    | 'firewall'
    | 'scaling'
    | 'volumes'
    | 'advanced'
    | 'danger';

export type SectionPage = SettingsNavPage<SectionId>;

/**
 * A group of pages in the sidebar (`SettingsShell`): General at the top and
 * Advanced and Danger zone at the bottom need no title.
 */
export type SettingsArea = SettingsNavGroup<SectionId>;

/**
 * The Settings tab's structure for one service (docs/08 §16): which pages
 * exist depends on the kind. Variables stay their own panel tab.
 */
export function settingsAreas(context: SettingsContext): SettingsArea[] {
    const { node, settings, canUpdate, canDelete } = context;
    const kind = node.kind;
    const web = kind === 'app' || kind === 'image';
    const listens = kind !== 'worker' && kind !== 'cron';
    const sourceIcon =
        settings.source.type === 'database'
            ? Database
            : settings.source.type === 'image'
              ? Container
              : GitBranch;
    const page = (
        id: SectionId,
        label: string,
        icon: LucideIcon,
        when = true,
    ): SectionPage[] => (when ? [{ id, label, icon }] : []);

    const areas: SettingsArea[] = [
        {
            id: 'general',
            label: null,
            pages: page('general', 'General', SlidersHorizontal),
        },
        {
            id: 'build',
            label: 'Build & deploy',
            pages: [
                ...page('source', 'Source', sourceIcon),
                ...page('build', 'Build', Boxes, kind === 'app'),
                ...page('deploy', 'Deploy', Rocket, kind !== 'database'),
                ...page('schedule', 'Schedule', CalendarClock, kind === 'cron'),
            ],
        },
        {
            id: 'network',
            label: 'Network',
            pages: [
                ...page('domains', 'Domains', Link2, web),
                ...page('networking', 'Private network', Network, listens),
                ...page('firewall', 'Web firewall', ShieldCheck, web),
            ],
        },
        {
            id: 'resources',
            label: 'Resources',
            pages: [
                ...page('scaling', 'Scaling', Layers),
                ...page(
                    'volumes',
                    'Volumes',
                    HardDrive,
                    settings.volumes.length > 0 || (canUpdate && web),
                ),
            ],
        },
        {
            id: 'more',
            label: null,
            pages: [
                ...page('advanced', 'Advanced', Wrench),
                ...page(
                    'danger',
                    'Danger zone',
                    TriangleAlert,
                    canUpdate || canDelete,
                ),
            ],
        },
    ];

    return areas.filter((area) => area.pages.length > 0);
}

/** The page shown for `/settings` without a section (wide layout). */
export const DEFAULT_SECTION: SectionId = 'general';
