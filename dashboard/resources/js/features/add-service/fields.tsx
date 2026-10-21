import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';

export function Field({
    id,
    label,
    helper,
    error,
    children,
}: {
    id: string;
    label: string;
    helper?: ReactNode;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {helper && (
                <p className="type-small text-muted-foreground">{helper}</p>
            )}
            <InputError message={error} />
        </div>
    );
}

/**
 * Turns "ghcr.io/acme/web:1.2" or "acme/shop" into a service name.
 */
export function suggestName(value: string): string {
    const last = value.split('/').pop() ?? value;

    return last
        .replace(/[@:].*$/, '')
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 63);
}
