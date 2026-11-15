import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { slugFrom } from '@/lib/slug';

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

/** "ghcr.io/acme/web:1.2" or "acme/shop" → "web" / "shop". */
export function suggestName(value: string): string {
    const last = value.split('/').pop() ?? value;

    return slugFrom(last.replace(/[@:].*$/, ''), '');
}
