import { AppContent } from '@/components/laravel/app-content';
import { AppShell } from '@/components/laravel/app-shell';
import { AppSidebar } from '@/components/laravel/app-sidebar';
import { AppSidebarHeader } from '@/components/laravel/app-sidebar-header';
import { type BreadcrumbItem } from '@/types';
import { type PropsWithChildren } from 'react';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: PropsWithChildren<{ breadcrumbs?: BreadcrumbItem[] }>) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
