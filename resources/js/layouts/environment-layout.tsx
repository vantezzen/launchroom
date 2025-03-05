import { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import React from 'react';
import AppLayout from './app-layout';
import { EnvironmentNavbar } from './environment-navbar';

function EnvironmentLayout({
    children,
    title,
    subtitle,
    actions,
}: {
    children: React.ReactNode;
    title: string;
    subtitle?: string;
    actions?: React.ReactNode;
}) {
    const {
        props: { currentProject },
    } = usePage<SharedData>();

    return (
        <AppLayout fullSizeContent>
            <Head title={`${title} - ${currentProject.name}`} />
            <div className="flex w-full flex-col items-center">
                <EnvironmentNavbar />

                <div className="m-6 grid w-full max-w-5xl gap-3 p-8">
                    <div className="flex items-center justify-between">
                        <h1 className="text-3xl font-bold">{title}</h1>
                        <div>{actions}</div>
                    </div>
                    <p>{subtitle}</p>
                    <div>{children}</div>
                </div>
            </div>
        </AppLayout>
    );
}

export default EnvironmentLayout;
