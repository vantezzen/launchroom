import React from 'react';

function PageLayout({
    children,
    title,
    subtitle,
    actions,
}: {
    children: React.ReactNode;
    title?: string;
    subtitle?: string;
    actions?: React.ReactNode;
}) {
    return (
        <div className="m-6 grid gap-3">
            <div className="flex items-center justify-between">
                <h1 className="text-3xl font-bold">{title}</h1>
                <div>{actions}</div>
            </div>
            <p>{subtitle}</p>
            <div>{children}</div>
        </div>
    );
}

export default PageLayout;
