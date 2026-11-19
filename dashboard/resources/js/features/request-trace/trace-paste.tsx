import { router } from '@inertiajs/react';
import { Waypoints } from 'lucide-react';
import { useId, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { traceHref } from './links';
import { normaliseTrace } from './trace-model';

/**
 * The paste field for a `Launchroom-Trace` header: on submit it opens the
 * trace page with it (the server decodes it). Keeps what was pasted and
 * shows the server's answer when it couldn't read it.
 */
export function TracePasteForm({
    initial = '',
    error,
    autoFocus = false,
    compact = false,
    onSubmitted,
}: {
    initial?: string;
    /** The server's answer to the last paste. */
    error?: string | null;
    autoFocus?: boolean;
    /** In a popover: smaller field, no hint about where headers come from. */
    compact?: boolean;
    onSubmitted?: () => void;
}) {
    const id = useId();
    const [value, setValue] = useState(initial);
    const [local, setLocal] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const shown = local ?? (value === initial ? error : null) ?? null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const trace = normaliseTrace(value);

        if (trace === null) {
            setLocal(
                value.trim() === ''
                    ? 'Paste the value of a Launchroom-Trace header.'
                    : 'This doesn’t look like a Launchroom-Trace header.',
            );

            return;
        }

        setLocal(null);
        onSubmitted?.();
        router.visit(traceHref(trace), {
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3" noValidate>
            <div className="flex flex-col gap-1.5">
                <label htmlFor={id} className="type-label">
                    Launchroom-Trace header
                </label>
                <Textarea
                    id={id}
                    value={value}
                    onChange={(event) => {
                        setValue(event.target.value);
                        setLocal(null);
                    }}
                    onKeyDown={(event) => {
                        if (
                            event.key === 'Enter' &&
                            (event.metaKey || event.ctrlKey)
                        ) {
                            event.preventDefault();
                            event.currentTarget.form?.requestSubmit();
                        }
                    }}
                    autoFocus={autoFocus}
                    spellCheck={false}
                    autoComplete="off"
                    autoCapitalize="off"
                    placeholder="AQAAAB3k9f…"
                    rows={compact ? 3 : 4}
                    aria-invalid={shown ? true : undefined}
                    aria-describedby={shown ? `${id}-error` : `${id}-hint`}
                    className={cn(
                        'resize-none font-mono text-[13px] leading-5 [overflow-wrap:anywhere]',
                        compact ? 'max-h-32' : 'max-h-48',
                    )}
                />
                {shown ? (
                    <p
                        id={`${id}-error`}
                        role="alert"
                        className="type-small text-status-error"
                    >
                        {shown}
                    </p>
                ) : (
                    <p
                        id={`${id}-hint`}
                        className="type-small text-muted-foreground"
                    >
                        {compact
                            ? 'Every response through your edge servers carries one.'
                            : 'Find it in the response headers in your browser’s network tab, or with curl -I.'}
                    </p>
                )}
            </div>
            <div className="flex items-center justify-end gap-2">
                <Button type="submit" size="sm" disabled={busy}>
                    Trace
                </Button>
            </div>
        </form>
    );
}

/**
 * "Trace a request" with its paste field in a popover (the log
 * explorer's toolbar, the trace page's header).
 */
export function TracePastePopover({
    children,
    align = 'end',
}: {
    /** The trigger; defaults to an outline "Trace a request" button. */
    children?: ReactNode;
    align?: 'start' | 'center' | 'end';
}) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                {children ?? (
                    <Button variant="outline" size="sm" className="h-9">
                        <Waypoints aria-hidden="true" />
                        Trace a request
                    </Button>
                )}
            </PopoverTrigger>
            <PopoverContent
                align={align}
                className="w-[min(24rem,calc(100vw-2rem))]"
            >
                <TracePasteForm
                    compact
                    autoFocus
                    onSubmitted={() => setOpen(false)}
                />
            </PopoverContent>
        </Popover>
    );
}
