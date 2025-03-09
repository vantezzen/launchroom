import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import * as React from 'react';

interface AppContentProps extends React.ComponentProps<'main'> {
    variant?: 'header' | 'sidebar';
    fullSize?: boolean;
}

export function AppContent({ variant = 'header', children, fullSize = false, ...props }: AppContentProps) {
    if (variant === 'sidebar') {
        return <SidebarInset {...props}>{children}</SidebarInset>;
    }

    return (
        <main className={cn('mx-auto flex h-full w-full flex-1 flex-col gap-4 rounded-xl', fullSize ? '' : 'max-w-5xl p-8')} {...props}>
            {children}
        </main>
    );
}
