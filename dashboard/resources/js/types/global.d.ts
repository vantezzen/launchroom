import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            docsUrl: string;
            sidebarOpen: boolean;
            teams: App.Data.Teams.TeamContextData | null;
            recoveryKit: App.Data.Backups.RecoveryKitStatusData | null;
            recoveryMode: boolean;
            /** Moving launchroom (docs/14 §13.4): writes paused, or the checklist after the move. */
            instanceMove: { frozen: boolean; checklist: boolean } | null;
            realtime: App.Data.Instance.RealtimeConfigData | null;
            onboardingProgress: App.Data.Instance.OnboardingProgressData | null;
            updateAvailable: boolean;
            /** Open "Action needed" findings of the current team (docs/22 §9.1). */
            securityActionNeeded: number;
            /** The control-plane server is under heavy load (docs/08 §4); managers only. */
            controlPlaneLoad: App.Data.Servers.ControlPlaneLoadData | null;
            version: string | null;
            [key: string]: unknown;
        };
    }
}
