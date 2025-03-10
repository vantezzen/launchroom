import useBaseUrl from '@/hooks/use-base-url';
import { cn } from '@/lib/utils';
import { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

// Navigation items configuration
export const navigationItems = [
    { name: 'Overview', href: '' },
    { name: 'Deployments', href: 'deployments' },
    { name: 'Metrics', href: 'metrics' },
    { name: 'Logs', href: 'logs' },
    { name: 'Settings', href: 'settings' },
] as const;

export function EnvironmentNavbar() {
    const { url } = usePage<SharedData>();
    const baseUrl = useBaseUrl();

    // Remove baseUrl from pathname to get current route
    const currentRoute = url.replace(baseUrl, '').split('/')[0];

    return (
        <nav className={cn('w-full border-b')}>
            {/* Scrollable container for mobile */}
            <div className="overflow-auto">
                <div className="flex h-16 items-center px-4">
                    {/* Navigation items */}
                    <div className="flex space-x-6">
                        {navigationItems.map((item) => {
                            const isActive = (item.href === '' && currentRoute === '') || (item.href !== '' && currentRoute === item.href);

                            return (
                                <Link
                                    key={item.name}
                                    href={`${baseUrl}${item.href}`}
                                    className={cn(
                                        'hover:text-foreground -mb-px flex h-16 items-center border-b-2 text-sm transition-colors',
                                        isActive ? 'border-foreground text-foreground' : 'text-muted-foreground border-transparent',
                                    )}
                                >
                                    {item.name}
                                </Link>
                            );
                        })}
                    </div>
                </div>
            </div>
        </nav>
    );
}
