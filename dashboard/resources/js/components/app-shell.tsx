import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';
import { CommandPalette } from '@/features/command-palette/command-palette';
import { initializeEcho } from '@/lib/echo';
import type { AppVariant } from '@/types';

type Props = {
    children: ReactNode;
    variant?: AppVariant;
};

export function AppShell({ children, variant = 'sidebar' }: Props) {
    const { sidebarOpen: isOpen, realtime } = usePage().props;

    // After signing in (no full page load) the first app page brings the
    // realtime settings; configure Echo before any child subscribes.
    initializeEcho(realtime);

    if (variant === 'header') {
        return (
            <div className="flex min-h-screen w-full flex-col">
                {children}
                <CommandPalette />
            </div>
        );
    }

    return (
        <SidebarProvider defaultOpen={isOpen}>
            {children}
            <CommandPalette />
        </SidebarProvider>
    );
}
