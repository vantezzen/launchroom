import { AppContent } from '@/components/laravel/app-content';
import { AppHeader } from '@/components/laravel/app-header';
import { AppShell } from '@/components/laravel/app-shell';
import { type BreadcrumbItem } from '@/types';
import type { PropsWithChildren } from 'react';

export default function AppHeaderLayout({
    children,
    breadcrumbs,
    fullSizeContent,
}: PropsWithChildren<{ breadcrumbs?: BreadcrumbItem[]; fullSizeContent?: boolean }>) {
    return (
        <AppShell>
            <AppHeader breadcrumbs={breadcrumbs} />
            <AppContent fullSize={fullSizeContent}>{children}</AppContent>
        </AppShell>
    );
}
