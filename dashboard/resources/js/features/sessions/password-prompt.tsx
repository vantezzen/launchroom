import { KeyRound } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Inline re-authentication before a session opens (docs/09 R33): one calm
 * card in the tab, never a redirect.
 */
export function PasswordPrompt({
    id,
    busy,
    error,
    action,
    onSubmit,
    onCancel,
}: {
    id: string;
    busy: boolean;
    error: string | null;
    action: string;
    onSubmit: (password: string) => void;
    onCancel: () => void;
}) {
    const [password, setPassword] = useState('');

    return (
        <form
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-card motion-safe:animate-enter"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit(password);
            }}
        >
            <div className="flex items-start gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-recessed text-muted-foreground">
                    <KeyRound aria-hidden="true" className="size-4" />
                </span>
                <div className="flex flex-col gap-0.5">
                    <p className="type-label">Confirm it’s you</p>
                    <p className="type-small text-muted-foreground">
                        Sessions are recorded in the audit log. Enter your
                        password to continue.
                    </p>
                </div>
            </div>
            <div className="flex flex-col gap-2">
                <Label htmlFor={id}>Password</Label>
                <Input
                    id={id}
                    type="password"
                    autoComplete="current-password"
                    autoFocus
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    className="sm:max-w-72"
                />
                {error && (
                    <p role="alert" className="type-small text-status-error">
                        {error}
                    </p>
                )}
            </div>
            <div className="flex gap-2">
                <Button
                    type="submit"
                    size="sm"
                    disabled={busy || password === ''}
                >
                    {action}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onCancel}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}
