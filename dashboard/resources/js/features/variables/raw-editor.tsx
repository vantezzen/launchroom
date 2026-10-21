import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { raw } from '@/routes/variables';
import { update as rawUpdate } from '@/routes/variables/raw';
import { api } from './api';

/**
 * Raw `.env` mode (docs/06 §3.4): the variables of this service in this
 * environment as a file. Secrets appear as a placeholder that keeps their
 * stored value; removing a line removes the variable from this environment.
 */
export function RawEditor({
    urls,
    placeholder,
    onSaved,
    onCancel,
}: {
    urls: { project: string; environment: string; service: string };
    placeholder: string;
    onSaved: (message: string) => void;
    onCancel: () => void;
}) {
    const { data, isPending } = useQuery({
        queryKey: ['variables-raw', urls.environment, urls.service],
        queryFn: ({ signal }) =>
            api<{ content: string }>('get', raw(urls).url, undefined, signal),
        gcTime: 0,
        staleTime: 0,
        refetchOnWindowFocus: false,
    });
    const [draft, setDraft] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const content = draft ?? data?.content ?? '';

    const save = async () => {
        setBusy(true);
        setError(null);

        try {
            const result = await api<App.Data.Variables.RawEditResult>(
                'put',
                rawUpdate(urls).url,
                { content },
            );
            const parts = [
                result.created.length > 0 && `${result.created.length} added`,
                result.updated.length > 0 && `${result.updated.length} changed`,
                result.removed.length > 0 && `${result.removed.length} removed`,
            ].filter(Boolean);
            onSaved(
                parts.length === 0
                    ? 'No changes.'
                    : `${parts.join(', ')}. Deploy to apply them.`,
            );
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'Could not save the file.',
            );
        } finally {
            setBusy(false);
        }
    };

    if (isPending) {
        return <Skeleton className="h-64 w-full" />;
    }

    return (
        <div className="flex flex-col gap-3">
            <p className="max-w-[80ch] type-small text-muted-foreground">
                One <code className="type-mono-small">KEY=value</code> per line.
                Secrets show as{' '}
                <code className="type-mono-small">{placeholder}</code>, which
                keeps their value. Removing a line removes the variable from
                this environment. Saving stages the changes.
            </p>
            <textarea
                aria-label="Variables as .env"
                value={content}
                onChange={(event) => setDraft(event.target.value)}
                rows={Math.min(24, Math.max(8, content.split('\n').length + 1))}
                spellCheck={false}
                className="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 type-mono-small shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            />
            {error && (
                <p role="alert" className="type-body text-status-error">
                    {error}
                </p>
            )}
            <div className="flex gap-2">
                <Button
                    onClick={() => void save()}
                    disabled={busy || draft === null}
                >
                    Save
                </Button>
                <Button variant="secondary" onClick={onCancel}>
                    Cancel
                </Button>
            </div>
        </div>
    );
}
