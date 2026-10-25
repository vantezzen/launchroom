import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { reveal } from '@/routes/variables';
import { api } from './api';

export const MASK = '••••••••';

/**
 * A secret's value: masked, with Reveal for members allowed to see secrets
 * (docs/06 §3.1). Revealing is throttled and audited server-side. The
 * value lives only in this component's state and is dropped on Hide.
 */
export function RevealValue({
    project,
    environment,
    variable,
    name,
    canReveal,
}: {
    project: string;
    environment: string;
    variable: string;
    name: string;
    canReveal: boolean;
}) {
    const [value, setValue] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const request = async () => {
        setBusy(true);
        setError(null);

        try {
            const result = await api<{ value: string }>(
                'post',
                reveal({ project, environment, variable }).url,
                {},
            );
            setValue(result.value);
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'Could not reveal the value.',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <span className="flex min-w-0 items-start gap-1">
            <span className="min-w-0 type-mono [overflow-wrap:anywhere] whitespace-pre-wrap">
                {value ?? <span className="text-muted-foreground">{MASK}</span>}
            </span>
            {canReveal && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-6 shrink-0"
                    disabled={busy}
                    aria-label={
                        value === null ? `Reveal ${name}` : `Hide ${name}`
                    }
                    onClick={() =>
                        value === null ? void request() : setValue(null)
                    }
                >
                    {value === null ? (
                        <Eye className="size-3.5" />
                    ) : (
                        <EyeOff className="size-3.5" />
                    )}
                </Button>
            )}
            {error && (
                <span className="type-small text-status-error">{error}</span>
            )}
        </span>
    );
}
