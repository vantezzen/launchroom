import { Badge } from '@/components/ui/badge';
import { Project } from '@/types';
import { timeAgo } from '@/utils/time';
import { Github } from 'lucide-react';

interface ProjectGridProps {
    projects: Project[];
    onProjectClick?: (project: Project) => void;
}

export function ProjectGrid({ projects, onProjectClick }: ProjectGridProps) {
    // Extract repository owner and name from repository string
    const getRepoInfo = (repository: string) => {
        const parts = repository.split('/');
        return {
            owner: parts[parts.length - 2] || '',
            name: parts[parts.length - 1] || '',
        };
    };

    // Generate project icon from name
    const getProjectIcon = (name: string) => {
        return name.charAt(0).toUpperCase();
    };

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {projects.map((project) => (
                <div
                    key={project.id}
                    className="group bg-card text-card-foreground relative flex cursor-pointer flex-col space-y-3 rounded-lg border p-4 shadow-sm transition-all hover:shadow-md"
                    onClick={() => onProjectClick?.(project)}
                >
                    <div className="flex items-start justify-between">
                        <div className="flex items-center space-x-3">
                            {/* Project Icon */}
                            <div className={`flex h-10 w-10 items-center justify-center rounded-lg bg-zinc-900 font-medium text-white`}>
                                {getProjectIcon(project.name)}
                            </div>

                            {/* Project Info */}
                            <div>
                                <h3 className="leading-none font-semibold tracking-tight">{project.name}</h3>
                                <p className="text-muted-foreground text-sm">{project.slug}</p>
                            </div>
                        </div>

                        {/* Action Buttons */}
                        {/* <div className="flex items-center space-x-2">
                            <Button variant="ghost" size="icon" className="h-8 w-8 opacity-0 transition-opacity group-hover:opacity-100">
                                <Activity className="h-4 w-4" />
                                <span className="sr-only">View Analytics</span>
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="icon" className="h-8 w-8 opacity-0 transition-opacity group-hover:opacity-100">
                                        <MoreVertical className="h-4 w-4" />
                                        <span className="sr-only">Open menu</span>
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem>View Project</DropdownMenuItem>
                                    <DropdownMenuItem>View Git Repository</DropdownMenuItem>
                                    <DropdownMenuItem>Project Settings</DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div> */}
                    </div>

                    {/* Repository Info */}
                    <div className="flex items-center space-x-2">
                        <Badge variant="secondary" className="flex items-center space-x-1">
                            <Github className="h-3 w-3" />
                            <span>
                                {getRepoInfo(project.repository).owner}/{getRepoInfo(project.repository).name}
                            </span>
                        </Badge>
                    </div>

                    {/* Team and Date */}
                    <div className="text-muted-foreground flex items-center justify-between text-sm">
                        <span>{project.team?.name}</span>
                        <span>Updated {timeAgo(project.updated_at)}</span>
                    </div>
                </div>
            ))}
        </div>
    );
}
