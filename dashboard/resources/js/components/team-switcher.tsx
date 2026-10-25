import { router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import { useState } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { CreateTeamDialog } from '@/features/teams/create-team-dialog';
import { current } from '@/routes/teams';

/**
 * The team selector in the sidebar header (design-system §13.2): the team's
 * initial, name and role; the menu lists every team and, for owners and
 * admins, "Create team".
 */
export function TeamSwitcher() {
    const { teams } = usePage().props;
    const { isMobile, state } = useSidebar();
    const [creating, setCreating] = useState(false);

    if (!teams?.current) {
        return null;
    }

    const active = teams.current;

    return (
        <SidebarMenu className="min-w-0 flex-1">
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent"
                            data-test="team-switcher"
                        >
                            {/* The icon rail has no room for the name: the team's initial stands in. */}
                            <span
                                aria-hidden="true"
                                className="hidden size-8 shrink-0 items-center justify-center rounded-md border bg-background type-label text-foreground group-data-[collapsible=icon]:flex"
                            >
                                {active.name.charAt(0).toUpperCase()}
                            </span>
                            <span className="grid min-w-0 flex-1 text-left group-data-[collapsible=icon]:sr-only">
                                <span className="truncate type-label">
                                    {active.name}
                                </span>
                                <span className="truncate type-small text-muted-foreground">
                                    {active.role
                                        ? active.role.charAt(0).toUpperCase() +
                                          active.role.slice(1)
                                        : ''}
                                </span>
                            </span>
                            <ChevronsUpDown className="ml-auto size-4 group-data-[collapsible=icon]:hidden" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="min-w-56"
                        align="start"
                        side={
                            !isMobile && state === 'collapsed'
                                ? 'right'
                                : 'bottom'
                        }
                    >
                        <DropdownMenuLabel className="type-small text-muted-foreground">
                            Teams
                        </DropdownMenuLabel>
                        {teams.all.map((team) => (
                            <DropdownMenuItem
                                key={team.id}
                                onSelect={() => {
                                    if (team.id !== active.id) {
                                        router.put(current(team.id).url);
                                    }
                                }}
                            >
                                <span className="flex-1 truncate">
                                    {team.name}
                                </span>
                                {team.id === active.id && (
                                    <Check
                                        className="size-4"
                                        aria-label="Current team"
                                    />
                                )}
                            </DropdownMenuItem>
                        ))}
                        {teams.canCreateTeam && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() => setCreating(true)}
                                    data-test="create-team"
                                >
                                    <Plus aria-hidden="true" />
                                    Create team
                                </DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
            <CreateTeamDialog open={creating} onOpenChange={setCreating} />
        </SidebarMenu>
    );
}
