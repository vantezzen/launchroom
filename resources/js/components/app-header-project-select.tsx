import { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';

function AppHeaderProjectSelect() {
    const {
        props: {
            auth: { teams },
            currentTeam,
            currentProject,
        },
    } = usePage<SharedData>();

    console.log('teams', currentProject);

    return (
        <div className="flex items-center space-x-2">
            {/* Team Select */}
            <Select
                onValueChange={(value) => {
                    router.visit(`/teams/${value}`);
                }}
                value={currentTeam?.slug}
            >
                <SelectTrigger className="min-w-50">
                    <SelectValue placeholder="Select a team" />
                </SelectTrigger>

                <SelectContent>
                    {teams.map((team) => (
                        <SelectItem key={team.slug} value={team.slug}>
                            {team.name}
                        </SelectItem>
                    ))}

                    <SelectItem value="create">New Team</SelectItem>
                </SelectContent>
            </Select>

            <div className="text-zinc-500">/</div>

            {/* Project Select */}
            <Select
                onValueChange={(value) => {
                    router.visit(`/teams/${currentTeam?.slug}/projects/${value}`);
                }}
                value={currentProject?.slug}
            >
                <SelectTrigger className="min-w-50">
                    <SelectValue placeholder="Select a project" />
                </SelectTrigger>

                <SelectContent>
                    {currentTeam?.projects?.map((project) => (
                        <SelectItem key={project.slug} value={project.slug}>
                            {project.name}
                        </SelectItem>
                    ))}

                    <SelectItem value="create">New Project</SelectItem>
                </SelectContent>
            </Select>
        </div>
    );
}

export default AppHeaderProjectSelect;
