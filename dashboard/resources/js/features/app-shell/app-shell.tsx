import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';
import { CommandPalette } from '@/features/command-palette/command-palette';
import { MyDeployToasts } from '@/features/deployments/deploy-toasts';
import { initializeEcho } from '@/lib/echo';
import { PhoneNav } from './phone-nav';

export function AppShell({ children }: { children: ReactNode }) {
    const { sidebarOpen: isOpen, realtime } = usePage().props;

    // After signing in (no full page load) the first app page brings the
    // realtime settings; configure Echo before any child subscribes.
    initializeEcho(realtime);

    return (
        <SidebarProvider defaultOpen={isOpen}>
            {children}
            <PhoneNav />
            <CommandPalette />
            <MyDeployToasts />
        </SidebarProvider>
    );
}
