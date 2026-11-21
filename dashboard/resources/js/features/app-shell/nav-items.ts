import {
    Activity,
    BookOpen,
    ChartColumnStacked,
    FolderKanban,
    ListChecks,
    ScrollText,
    Server,
    Settings,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';
import { index as activity } from '@/routes/activity';
import { index as analytics } from '@/routes/analytics';
import { index as teamLogs } from '@/routes/logs';
import { show as onboarding } from '@/routes/onboarding';
import { index as projects } from '@/routes/projects';
import { index as security } from '@/routes/security';
import { index as servers } from '@/routes/servers';
import { index as settingsHome } from '@/routes/settings';
import type { NavMainItem } from './nav-main';

/**
 * Where an item sits in the sidebar (docs/08 §1, D114): `start` under
 * Search, `main` the pages people use every day, `bottom` the quiet group
 * pinned above the account row.
 */
export type NavPlacement = 'start' | 'main' | 'bottom';

export type TeamNavItem = NavMainItem & {
    /** Extra words ⌘K matches on. */
    keywords: string;
    /** The `g` shortcut, shown in ⌘K. */
    shortcut?: string;
    placement: NavPlacement;
};

type Signals = Pick<
    App.Data.Inertia.SharedPropsData,
    | 'onboardingProgress'
    | 'updateAvailable'
    | 'teams'
    | 'securityActionNeeded'
    | 'openAlertCount'
    | 'docsUrl'
>;

/** A badge number that stays readable: 1–99, then "99+". */
export function badge(count: number): string | undefined {
    if (count <= 0) {
        return undefined;
    }

    return count > 99 ? '99+' : String(count);
}

/**
 * The team pages, in sidebar order (docs/08 §1, D114). The sidebar, the
 * phone bar with its More sheet and ⌘K's Navigate group are all built from
 * this list, so they can't drift. The main group follows daily use:
 * Projects (home, where deploys happen), Servers, Logs (the first stop
 * when something breaks), Analytics, Security (its count is what pulls
 * people in). Activity, Docs and Settings are reached less often and sit
 * at the bottom; Backups is a Settings page.
 */
export function teamNavItems(signals: Signals): TeamNavItem[] {
    const progress = signals.onboardingProgress;
    const items: (TeamNavItem | false)[] = [
        // The first-run checklist stays reachable until every step is
        // done, or the owner removed it once only skipped steps were left
        // (docs/08 §3, D109).
        Boolean(
            progress &&
            !progress.complete &&
            !(progress.settled && progress.dismissed),
        ) && {
            title: 'Get started',
            href: onboarding(),
            icon: ListChecks,
            meta: progress ? `${progress.done}/${progress.total}` : undefined,
            keywords: 'onboarding checklist setup first steps',
            placement: 'start',
        },
        {
            title: 'Projects',
            href: projects(),
            icon: FolderKanban,
            keywords: 'apps home',
            shortcut: 'g p',
            placement: 'main',
        },
        {
            title: 'Servers',
            href: servers(),
            icon: Server,
            keywords: 'machines hosts fleet',
            shortcut: 'g s',
            placement: 'main',
        },
        // Owners and admins (docs/08 §12).
        signals.teams?.canSearchLogs === true && {
            title: 'Logs',
            href: teamLogs(),
            icon: ScrollText,
            keywords: 'search logs all projects',
            placement: 'main',
        },
        {
            title: 'Analytics',
            href: analytics(),
            icon: ChartColumnStacked,
            keywords: 'traffic requests visitors',
            placement: 'main',
        },
        {
            title: 'Security',
            href: security(),
            icon: signals.securityActionNeeded ? ShieldAlert : ShieldCheck,
            // Things to fix: packages that need action (docs/22 §9.1).
            meta: badge(signals.securityActionNeeded),
            signal: signals.securityActionNeeded > 0,
            keywords: 'vulnerabilities findings firewall waf cve',
            placement: 'main',
        },
        {
            title: 'Activity',
            href: activity(),
            icon: Activity,
            // Open alerts; the number is the non-colour cue.
            meta: badge(signals.openAlertCount),
            signal: signals.openAlertCount > 0,
            keywords: 'alerts feed events history what happened',
            placement: 'bottom',
        },
        {
            title: 'Docs',
            href: signals.docsUrl,
            icon: BookOpen,
            external: true,
            keywords: 'documentation help guides',
            placement: 'bottom',
        },
        {
            title: 'Settings',
            href: settingsHome(),
            icon: Settings,
            // Settings pages live under /settings/*; any of them is "here".
            activePrefix: '/settings',
            dot: signals.updateAvailable ? 'Update ready' : undefined,
            keywords:
                'team account preferences backups storage restore members',
            placement: 'bottom',
        },
    ];

    return items.filter((item): item is TeamNavItem => item !== false);
}

/** The items of one sidebar group, in order. */
export function navGroup(
    items: TeamNavItem[],
    placement: NavPlacement,
): TeamNavItem[] {
    return items.filter((item) => item.placement === placement);
}

/** How many places the phone bar links to; Search and More follow. */
const PHONE_BAR_LINKS = 3;

/**
 * Phones (D114): the bar holds the first three main pages (Projects,
 * Servers, then Logs or, without it, Analytics); the More sheet lists the
 * rest in sidebar order, Get started first.
 */
export function phoneNav(items: TeamNavItem[]): {
    bar: TeamNavItem[];
    more: TeamNavItem[];
} {
    const bar = navGroup(items, 'main').slice(0, PHONE_BAR_LINKS);

    return { bar, more: items.filter((item) => !bar.includes(item)) };
}
