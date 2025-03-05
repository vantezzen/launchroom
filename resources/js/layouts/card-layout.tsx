import { Card, CardContent, CardDescription, CardTitle } from '@/components/ui/card';
import React from 'react';

function CardLayout({ children, title, subtitle }: { children: React.ReactNode; title?: string; subtitle?: string }) {
    return (
        <Card className="m-12">
            <CardTitle className="text-2xl">{title}</CardTitle>
            <CardDescription>{subtitle}</CardDescription>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

export default CardLayout;
