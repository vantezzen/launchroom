import {
    Boxes,
    CalendarClock,
    Container,
    Database,
    GitBranch,
    Globe,
    HardDrive,
    Hammer,
    Layers,
    Link2,
    Network,
    Rocket,
    SlidersHorizontal,
    TriangleAlert,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { SettingsContext } from './settings-context';

export type SectionId =
    | 'general'
    | 'source'
    | 'build'
    | 'deploy'
    | 'schedule'
    | 'domains'
    | 'networking'
    | 'scaling'
    | 'volumes'
    | 'advanced'
    | 'danger';

export type SectionPage = {
    id: SectionId;
    label: string;
    icon: LucideIcon;
};

/**
 * A sidebar entry: one page, or an area with several pages that opens a
 * sub-menu (back arrow + area title). An area left with a
 * single page for this kind of service renders as that page.
 */
export type SettingsArea = {
    id: string;
    label: string;
    icon: LucideIcon;
    pages: SectionPage[];
};

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

    const areas: SettingsArea[] = [
        {
            id: 'general',
            label: 'General',
            icon: SlidersHorizontal,
            pages: [
                { id: 'general', label: 'General', icon: SlidersHorizontal },
            ],
        },
        {
            id: 'build',
            label: 'Build & deploy',
            icon: Hammer,
            pages: [
                { id: 'source', label: 'Source', icon: sourceIcon },
                ...(kind === 'app'
                    ? [{ id: 'build' as const, label: 'Build', icon: Boxes }]
                    : []),
                ...(kind !== 'database'
                    ? [{ id: 'deploy' as const, label: 'Deploy', icon: Rocket }]
                    : []),
                ...(kind === 'cron'
                    ? [
                          {
                              id: 'schedule' as const,
                              label: 'Schedule',
                              icon: CalendarClock,
                          },
                      ]
                    : []),
            ],
        },
        {
            id: 'network',
            label: 'Network',
            icon: Globe,
            pages: [
                ...(web
                    ? [
                          {
                              id: 'domains' as const,
                              label: 'Domains',
                              icon: Link2,
                          },
                      ]
                    : []),
                ...(listens
                    ? [
                          {
                              id: 'networking' as const,
                              label: 'Private network',
                              icon: Network,
                          },
                      ]
                    : []),
            ],
        },
        {
            id: 'scaling',
            label: 'Scaling',
            icon: Layers,
            pages: [{ id: 'scaling', label: 'Scaling', icon: Layers }],
        },
        ...(settings.volumes.length > 0 || (canUpdate && web)
            ? [
                  {
                      id: 'volumes',
                      label: 'Volumes',
                      icon: HardDrive,
                      pages: [
                          {
                              id: 'volumes' as const,
                              label: 'Volumes',
                              icon: HardDrive,
                          },
                      ],
                  },
              ]
            : []),
        {
            id: 'advanced',
            label: 'Advanced',
            icon: Wrench,
            pages: [{ id: 'advanced', label: 'Advanced', icon: Wrench }],
        },
        ...(canUpdate || canDelete
            ? [
                  {
                      id: 'danger',
                      label: 'Danger zone',
                      icon: TriangleAlert,
                      pages: [
                          {
                              id: 'danger' as const,
                              label: 'Danger zone',
                              icon: TriangleAlert,
                          },
                      ],
                  },
              ]
            : []),
    ];

    return areas
        .filter((area) => area.pages.length > 0)
        .map((area) =>
            area.pages.length === 1
                ? {
                      ...area,
                      label: area.pages[0].label,
                      icon: area.pages[0].icon,
                  }
                : area,
        );
}

export function allPages(areas: SettingsArea[]): SectionPage[] {
    return areas.flatMap((area) => area.pages);
}

export function areaOf(
    areas: SettingsArea[],
    section: string | null,
): SettingsArea | undefined {
    return areas.find((area) => area.pages.some((page) => page.id === section));
}

/** The page shown for `/settings` without a section (wide layout). */
export const DEFAULT_SECTION: SectionId = 'general';
