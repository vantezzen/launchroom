import { Link, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { NavMain } from './nav-main';
import { navGroup, teamNavItems } from './nav-items';
import { NavUser } from './nav-user';
import { TeamSwitcher } from './team-switcher';
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
import { modShortcut } from '@/lib/platform';

/**
 * The visible "Search ⌘K" field (flows §17): opens the command palette.
 */
function SearchField() {
    return (
        <SidebarGroup className="py-0 pb-1">
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
                            {modShortcut('K')}
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

/**
 * The team sidebar (docs/08 §1, D114): the mark and team, Search and Get
 * started, the daily pages, then a quiet group pinned to the bottom
 * (Activity, Docs, Settings) with attention notices between it and the
 * account, the way hosting dashboards keep "Action required" there.
 */
export function AppSidebar() {
    const props = usePage().props;
    const items = teamNavItems(props);

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
                <div className="flex flex-col gap-1">
                    <SearchField />
                    <NavMain items={navGroup(items, 'start')} label="Setup" />
                </div>
                <NavMain items={navGroup(items, 'main')} label="Pages" />
            </SidebarContent>

            <SidebarFooter className="gap-2">
                <NavMain
                    items={navGroup(items, 'bottom')}
                    label="More"
                    className="px-0"
                />
                <ControlPlaneLoadNotice />
                <div className="flex flex-col gap-1">
                    <NavUser />
                    <AppVersion />
                </div>
            </SidebarFooter>
        </Sidebar>
    );
}
