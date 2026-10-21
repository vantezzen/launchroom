import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { reveal } from '@/routes/variables';
import { api, RequestFailed } from './api';

export const MASK = '••••••••';

/**
 * A secret's value: masked, with Reveal for members allowed to see secrets
 * (docs/06 §3.1). Revealing asks for the password unless it was confirmed
 * recently and is audited server-side. The value lives only in this
 * component's state and is dropped on Hide.
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
    const [asking, setAsking] = useState(false);
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const request = async (withPassword?: string) => {
        setBusy(true);
        setError(null);

        try {
            const result = await api<{ value: string }>(
                'post',
                reveal({ project, environment, variable }).url,
                withPassword === undefined ? {} : { password: withPassword },
            );
            setValue(result.value);
            setAsking(false);
            setPassword('');
        } catch (failure) {
            if (failure instanceof RequestFailed && failure.status === 423) {
                setAsking(true);
            } else {
                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'Could not reveal the value.',
                );
            }
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
            {error && !asking && (
                <span className="type-small text-status-error">{error}</span>
            )}
            <Dialog open={asking} onOpenChange={setAsking}>
                <DialogContent>
                    <DialogTitle>Confirm your password</DialogTitle>
                    <DialogDescription>
                        Revealing {name} is recorded in the audit log. Confirm
                        your password to continue.
                    </DialogDescription>
                    <form
                        className="flex flex-col gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void request(password);
                        }}
                    >
                        <div className="flex flex-col gap-2">
                            <Label htmlFor={`reveal-password-${variable}`}>
                                Password
                            </Label>
                            <Input
                                id={`reveal-password-${variable}`}
                                type="password"
                                autoComplete="current-password"
                                value={password}
                                onChange={(event) =>
                                    setPassword(event.target.value)
                                }
                                aria-invalid={error !== null || undefined}
                                autoFocus
                            />
                            {error && (
                                <p className="type-small text-status-error">
                                    {error}
                                </p>
                            )}
                        </div>
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button type="button" variant="secondary">
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={busy || password === ''}
                            >
                                Reveal
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </span>
    );
}
