import {
    Activity,
    ChartColumnStacked,
    DatabaseBackup,
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
import { index as backups } from '@/routes/backups';
import { index as teamLogs } from '@/routes/logs';
import { show as onboarding } from '@/routes/onboarding';
import { index as projects } from '@/routes/projects';
import { index as security } from '@/routes/security';
import { index as servers } from '@/routes/servers';
import { index as settingsHome } from '@/routes/settings';
import type { NavMainItem } from './nav-main';

export type TeamNavItem = NavMainItem & {
    /** Extra words ⌘K matches on. */
    keywords: string;
    /** The `g` shortcut, shown in ⌘K. */
    shortcut?: string;
};

type Signals = Pick<
    App.Data.Inertia.SharedPropsData,
    | 'onboardingProgress'
    | 'updateAvailable'
    | 'teams'
    | 'securityActionNeeded'
    | 'openAlertCount'
>;

/** A badge number that stays readable: 1–99, then "99+". */
export function badge(count: number): string | undefined {
    if (count <= 0) {
        return undefined;
    }

    return count > 99 ? '99+' : String(count);
}

/**
 * The team pages, in sidebar order (docs/08 §1). The sidebar and ⌘K's
 * Navigate group are both built from this list, so they can't drift.
 */
export function teamNavItems(signals: Signals): TeamNavItem[] {
    const progress = signals.onboardingProgress;

    return [
        // The first-run checklist stays reachable until every step is
        // done, or the owner removed it once only skipped steps were left
        // (docs/08 §3, D109).
        ...(progress &&
        !progress.complete &&
        !(progress.settled && progress.dismissed)
            ? [
                  {
                      title: 'Get started',
                      href: onboarding(),
                      icon: ListChecks,
                      meta: `${progress.done}/${progress.total}`,
                      keywords: 'onboarding checklist setup first steps',
                  },
              ]
            : []),
        {
            title: 'Projects',
            href: projects(),
            icon: FolderKanban,
            keywords: 'apps home',
            shortcut: 'g p',
        },
        {
            title: 'Activity',
            href: activity(),
            icon: Activity,
            // Open alerts; the number is the non-colour cue.
            meta: badge(signals.openAlertCount),
            keywords: 'alerts feed events history what happened',
        },
        {
            title: 'Servers',
            href: servers(),
            icon: Server,
            keywords: 'machines hosts fleet',
            shortcut: 'g s',
        },
        {
            title: 'Backups',
            href: backups(),
            icon: DatabaseBackup,
            keywords: 'backup restore snapshots storage bucket recovery kit',
        },
        {
            title: 'Analytics',
            href: analytics(),
            icon: ChartColumnStacked,
            keywords: 'traffic requests visitors',
        },
        {
            title: 'Security',
            href: security(),
            icon: signals.securityActionNeeded ? ShieldAlert : ShieldCheck,
            // Things to fix: packages that need action (docs/22 §9.1).
            meta: badge(signals.securityActionNeeded),
            keywords: 'vulnerabilities findings firewall waf cve',
        },
        ...(signals.teams?.canSearchLogs
            ? [
                  {
                      title: 'Logs',
                      href: teamLogs(),
                      icon: ScrollText,
                      keywords: 'search logs all projects',
                  },
              ]
            : []),
        {
            title: 'Settings',
            href: settingsHome(),
            icon: Settings,
            // Settings pages live under /settings/*; any of them is "here".
            activePrefix: '/settings',
            dot: signals.updateAvailable ? 'Update ready' : undefined,
            keywords: 'team account preferences',
        },
    ];
}
