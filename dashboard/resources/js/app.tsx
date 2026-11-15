import { createInertiaApp } from '@inertiajs/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { ReconnectBanner } from '@/components/reconnect-banner';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/features/app-shell/app-layout';
import { initializeConnection } from '@/lib/connection';
import { initializeEcho } from '@/lib/echo';
import { pageVisitOptions } from '@/lib/motion';
import { queryClient } from '@/lib/query-client';

const appName = import.meta.env.VITE_APP_NAME || 'launchroom';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // Team pages by default; project, settings and signed-out pages declare
    // their own (`Page.layout`).
    layout: () => AppLayout,
    // Page changes cross-fade via the View Transitions API (design-system §8).
    defaults: {
        visitOptions: pageVisitOptions,
    },
    strictMode: true,
    withApp(app, { page }) {
        initializeEcho(page.props.realtime);
        // One "Reconnecting" banner instead of stacked error dialogs, and a
        // full reload when a new build answers (lib/connection.ts).
        initializeConnection(page.version ?? null);

        return (
            <QueryClientProvider client={queryClient}>
                <TooltipProvider delayDuration={0}>
                    {app}
                    <ReconnectBanner />
                    <Toaster />
                </TooltipProvider>
            </QueryClientProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();
