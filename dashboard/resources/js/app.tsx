import { createInertiaApp } from '@inertiajs/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { ReconnectBanner } from '@/components/reconnect-banner';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import ProjectLayout from '@/layouts/project-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { initializeConnection } from '@/lib/connection';
import { initializeEcho } from '@/lib/echo';
import { pageVisitOptions } from '@/lib/motion';
import { queryClient } from '@/lib/query-client';

const appName = import.meta.env.VITE_APP_NAME || 'launchroom';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // Signed-out pages draw the shared AuthFrame themselves
            // (features/auth/auth-frame.tsx): no layout around them.
            case name.startsWith('auth/') ||
                name === 'setup' ||
                name.startsWith('invitations/') ||
                // The old dashboard after launchroom moved (docs/14 §13.4).
                name === 'moved' ||
                // CLI sign-in approval (docs/16 §4): one calm decision.
                name.startsWith('cli/'):
                return undefined;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            // Project pages (canvas, project settings, project deployments)
            // get the project top bar; the projects list and the import
            // flow (no project yet) are team pages.
            case name.startsWith('projects/') &&
                ![
                    'projects/index',
                    'projects/new',
                    'projects/new-compose',
                    'projects/templates',
                ].includes(name):
                return ProjectLayout;
            default:
                return AppLayout;
        }
    },
    // Page changes cross-fade via the View Transitions API (design-system §8).
    defaults: {
        visitOptions: pageVisitOptions,
    },
    strictMode: true,
    withApp(app, { page }) {
        // Realtime settings arrive with the first page's shared props.
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

// This will set light / dark mode on load...
initializeTheme();
