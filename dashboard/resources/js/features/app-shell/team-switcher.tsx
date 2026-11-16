import { router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import { StatusIcon } from '@/components/status';
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
import { index as servers } from '@/routes/servers';
import { current } from '@/routes/teams';

/**
 * The team selector in the sidebar header (design-system §13.2): the team's
 * name and, under it, how its servers are ("2 servers online", "1 server
 * down"; the role for people who can't see servers). The menu starts with
 * the team and your role, links the servers' line to Servers, then lists
 * every team and, for owners and admins, "Create team".
 */
export function TeamSwitcher() {
    const { teams, fleet } = usePage().props;
    const { isMobile, state } = useSidebar();
    const [creating, setCreating] = useState(false);

    if (!teams?.current) {
        return null;
    }

    const active = teams.current;
    const role = active.role
        ? active.role.charAt(0).toUpperCase() + active.role.slice(1)
        : null;

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
                                {fleet ? (
                                    <span
                                        className="flex min-w-0 items-center gap-1.5 type-small text-muted-foreground"
                                        data-test="team-fleet"
                                    >
                                        <StatusIcon
                                            state={fleet.state}
                                            className="size-3"
                                        />
                                        <span className="truncate">
                                            {fleet.label}
                                        </span>
                                    </span>
                                ) : (
                                    <span className="truncate type-small text-muted-foreground">
                                        {role}
                                    </span>
                                )}
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
                        <DropdownMenuLabel className="flex flex-col gap-0.5">
                            <span className="truncate type-label">
                                {active.name}
                            </span>
                            {role && (
                                <span className="type-small font-normal text-muted-foreground">
                                    You’re{' '}
                                    {role === 'Admin' || role === 'Owner'
                                        ? 'an'
                                        : 'a'}{' '}
                                    {role.toLowerCase()}
                                </span>
                            )}
                        </DropdownMenuLabel>
                        {fleet && (
                            <DropdownMenuItem
                                onSelect={() => router.visit(servers())}
                            >
                                <StatusIcon state={fleet.state} />
                                <span className="flex-1 truncate">
                                    {fleet.label}
                                </span>
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuSeparator />
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
