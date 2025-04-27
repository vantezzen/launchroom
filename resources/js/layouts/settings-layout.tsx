import { Card } from '@/components/ui/card';
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
            <div className="grid grid-cols-12 gap-6">
                {/* Sidebar */}
                <div className="col-span-12 md:col-span-3">
                    <Card className="overflow-hidden">
                        <div className="divide-y divide-gray-200">
                            {routes.map((item) => (
                                <Link
                                    key={item.name}
                                    href={item.route}
                                    className={cn(
                                        'block px-4 py-3 text-sm hover:bg-gray-50',
                                        item.isActive ? 'text-primary bg-gray-50 font-medium' : 'text-gray-700',
                                    )}
                                >
                                    {item.name}
                                </Link>
                            ))}
                        </div>
                    </Card>
                </div>

                {/* Main content */}
                <div className="col-span-12 md:col-span-9">{children}</div>
            </div>
        </EnvironmentLayout>
    );
}
