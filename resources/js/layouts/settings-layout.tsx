import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import EnvironmentLayout from '@/layouts/environment-layout';
import { cn } from '@/lib/utils';
import { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

interface SettingsLayoutProps extends PropsWithChildren {
    title: string;
    subtitle?: string;
}

export default function SettingsLayout({ children, title, subtitle }: SettingsLayoutProps) {
    const { currentTeam, currentProject, currentEnvironment } = usePage<SharedData>().props;

    const routes = [
        {
            name: 'Project Settings',
            route: route('teams.projects.environments.settings', {
                team: currentTeam?.slug,
                project: currentProject?.slug,
                environment: currentEnvironment?.id,
            }),
            isActive: route().current('teams.projects.environments.settings'),
        },
        {
            name: 'Domains',
            route: route('teams.projects.environments.settings.domains', {
                team: currentTeam?.slug,
                project: currentProject?.slug,
                environment: currentEnvironment?.id,
            }),
            isActive: route().current('teams.projects.environments.settings.domains'),
        },
    ];

    return (
        <EnvironmentLayout title={title} subtitle={subtitle}>
            <div className="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-col space-y-1 space-x-0">
                        {routes.map((item) => (
                            <Button
                                key={item.route}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': item.isActive,
                                })}
                            >
                                <Link href={item.route} prefetch>
                                    {item.name}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">{children}</section>
                </div>
            </div>
        </EnvironmentLayout>
    );
}
