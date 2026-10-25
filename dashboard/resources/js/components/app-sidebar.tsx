import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BookOpen,
    ChartColumnStacked,
    FolderKanban,
    ListChecks,
    ScrollText,
    Search,
    Server,
    Settings,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { NavMain } from '@/components/nav-main';
import type { NavMainItem } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { TeamSwitcher } from '@/components/team-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { openCommandPalette } from '@/features/command-palette/command-palette';
import { ControlPlaneLoadNotice } from '@/features/servers/control-plane-load-notice';
import { dashboard } from '@/routes';
import { index as activity } from '@/routes/activity';
import { index as analytics } from '@/routes/analytics';
import { index as teamLogs } from '@/routes/logs';
import { show as onboarding } from '@/routes/onboarding';
import { index as projects } from '@/routes/projects';
import { index as security } from '@/routes/security';
import { index as servers } from '@/routes/servers';
import { index as settingsHome } from '@/routes/settings';
import { useDocsUrl } from '@/features/settings/settings-card';
import { useCurrentUrl } from '@/hooks/use-current-url';

const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

/**
 * The visible "Search ⌘K" field (flows §17): opens the command palette.
 */
function SearchField() {
    return (
        <SidebarGroup className="py-0">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        onClick={() => openCommandPalette()}
                        tooltip={{ children: 'Search' }}
                        className="h-9 border border-sidebar-border bg-background text-muted-foreground shadow-card group-data-[collapsible=icon]:border-transparent group-data-[collapsible=icon]:bg-transparent group-data-[collapsible=icon]:shadow-none hover:bg-background hover:text-foreground"
                    >
                        <Search aria-hidden="true" />
                        <span className="flex-1">Search</span>
                        <kbd className="ml-auto rounded-sm border px-1.5 font-sans type-small text-muted-foreground group-data-[collapsible=icon]:hidden">
                            {isMac ? '⌘K' : 'Ctrl K'}
                        </kbd>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarGroup>
    );
}

/**
 * "launchroom v0.1.0" under the user menu (config `launchroom.version`, from
 * the root VERSION file). Hidden on the icon rail.
 */
function AppVersion() {
    const { version } = usePage().props;

    if (!version) {
        return null;
    }

    return (
        <p
            className="truncate px-2 pb-0.5 type-small text-muted-foreground group-data-[collapsible=icon]:hidden"
            data-test="app-version"
        >
            launchroom {version.startsWith('v') ? version : `v${version}`}
        </p>
    );
}

export function AppSidebar() {
    const docsUrl = useDocsUrl();
    const {
        onboardingProgress: progress,
        updateAvailable,
        teams,
        securityActionNeeded,
    } = usePage().props;
    // Team-wide log search is for owners and admins (TeamPolicy::searchLogs).
    const canSearchLogs =
        teams?.permissions.includes('servers.manage') ?? false;
    const { isCurrentOrParentUrl } = useCurrentUrl();

    const items: NavMainItem[] = [
        // The first-run checklist stays reachable until every step is
        // done or skipped (docs/08 §3.1).
        ...(progress && !progress.complete
            ? [
                  {
                      title: 'Get started',
                      href: onboarding(),
                      icon: ListChecks,
                      meta: `${progress.done}/${progress.total}`,
                  },
              ]
            : []),
        { title: 'Projects', href: projects(), icon: FolderKanban },
        { title: 'Servers', href: servers(), icon: Server },
        { title: 'Analytics', href: analytics(), icon: ChartColumnStacked },
        {
            title: 'Security',
            href: security(),
            icon: securityActionNeeded ? ShieldAlert : ShieldCheck,
            // The number is the non-colour cue (docs/22 §9.1).
            meta: securityActionNeeded
                ? String(securityActionNeeded)
                : undefined,
        },
        ...(canSearchLogs
            ? [{ title: 'Logs', href: teamLogs(), icon: ScrollText }]
            : []),
        {
            title: 'Settings',
            href: settingsHome(),
            icon: Settings,
            // Settings pages live under /settings/*; any of them is "here".
            activePrefix: '/settings',
            dot: updateAvailable ? 'Update ready' : undefined,
        },
    ];

    return (
        <Sidebar
            collapsible="icon"
            variant="inset"
            className="[view-transition-name:lr-sidebar]"
        >
            {/* One row: the mark (home) and the team selector; stacked on the icon rail. */}
            <SidebarHeader className="flex-row items-center gap-1 group-data-[collapsible=icon]:flex-col group-data-[collapsible=icon]:gap-2">
                <Link
                    href={dashboard()}
                    prefetch
                    aria-label="launchroom home"
                    className="flex size-10 shrink-0 items-center justify-center rounded-md text-foreground ring-sidebar-ring outline-hidden transition-colors group-data-[collapsible=icon]:size-8 hover:bg-sidebar-accent focus-visible:ring-2"
                >
                    <AppLogoIcon className="size-7 group-data-[collapsible=icon]:size-6" />
                </Link>
                <TeamSwitcher />
            </SidebarHeader>

            <SidebarContent className="gap-3">
                <SearchField />
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter className="gap-1">
                <ControlPlaneLoadNotice />
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            tooltip="Activity"
                            isActive={isCurrentOrParentUrl(activity())}
                        >
                            <Link href={activity()} prefetch>
                                <Activity aria-hidden="true" />
                                <span>Activity</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <SidebarMenuButton asChild tooltip="Docs">
                            <a
                                href={docsUrl('')}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <BookOpen aria-hidden="true" />
                                <span>Docs</span>
                            </a>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <NavUser />
                <AppVersion />
            </SidebarFooter>
        </Sidebar>
    );
}
