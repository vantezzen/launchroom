import { CornerDownLeft, Info } from 'lucide-react';
import { forwardRef, useImperativeHandle, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { formatArgv, parseArgv } from './argv';
import { timeoutLabel } from './format';

export type CommandBarHandle = {
    /** Puts a command into the input (a catalogue row's argv) and focuses it. */
    fill: (argv: string[]) => void;
};

const HISTORY_KEY = 'launchroom.commands.history';
const HISTORY_SIZE = 20;

function readHistory(): string[] {
    try {
        const stored: unknown = JSON.parse(
            window.localStorage.getItem(HISTORY_KEY) ?? '[]',
        );

        return Array.isArray(stored)
            ? stored.filter((item): item is string => typeof item === 'string')
            : [];
    } catch {
        return [];
    }
}

function remember(command: string): void {
    try {
        const next = [
            command,
            ...readHistory().filter((item) => item !== command),
        ].slice(0, HISTORY_SIZE);
        window.localStorage.setItem(HISTORY_KEY, JSON.stringify(next));
    } catch {
        // Private mode: ↑ just has nothing to recall.
    }
}

/**
 * The command bar (docs/08 §22): one mono input with a `$` prompt and Run.
 * What is typed is split into argv in the browser like a shell splits
 * words, and nothing more; pipes and variables are refused with a
 * sentence. ↑ and ↓ recall this browser's last commands; Enter runs.
 */
export const CommandBar = forwardRef<
    CommandBarHandle,
    {
        /** Why nothing can run right now; null when it can. */
        blocked: string | null;
        /** A harmless command of the framework, shown as the placeholder. */
        placeholder: string[] | null;
        timeout: number;
        timeouts: number[];
        onTimeoutChange: (seconds: number) => void;
        onRun: (argv: string[]) => Promise<void>;
        /** The server's answer to the last Run (a conflict, a validation error). */
        error: string | null;
    }
>(function CommandBar(
    { blocked, placeholder, timeout, timeouts, onTimeoutChange, onRun, error },
    ref,
) {
    const input = useRef<HTMLInputElement>(null);
    const [value, setValue] = useState('');
    const [busy, setBusy] = useState(false);
    const [recall, setRecall] = useState(-1);
    const parsed = parseArgv(value);
    const problem = parsed.ok ? null : parsed.error;
    const empty = parsed.ok && parsed.argv.length === 0;
    const disabled = blocked !== null;

    useImperativeHandle(ref, () => ({
        fill: (argv) => {
            setValue(formatArgv(argv));
            setRecall(-1);
            input.current?.focus();
        },
    }));

    const submit = async () => {
        if (!parsed.ok || parsed.argv.length === 0 || busy || disabled) {
            return;
        }

        setBusy(true);

        try {
            await onRun(parsed.argv);
            remember(value.trim());
            setValue('');
            setRecall(-1);
        } catch {
            // The bar shows the server's sentence and keeps what was typed.
        } finally {
            setBusy(false);
        }
    };

    const step = (direction: 1 | -1) => {
        const history = readHistory();
        const next = Math.min(
            Math.max(recall + direction, -1),
            history.length - 1,
        );
        setRecall(next);
        setValue(next === -1 ? '' : history[next]);
    };

    const message = problem ?? error;

    return (
        <div
            className={cn(
                'focus-within:border-strong overflow-hidden rounded-xl border bg-card shadow-card transition-colors duration-fast',
                disabled && 'bg-recessed',
            )}
        >
            <form
                className="flex items-center gap-2 py-2 pr-2 pl-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    void submit();
                }}
            >
                <span
                    aria-hidden="true"
                    className="type-mono text-muted-foreground select-none"
                >
                    $
                </span>
                <input
                    ref={input}
                    value={value}
                    disabled={disabled}
                    onChange={(event) => {
                        setValue(event.target.value);
                        setRecall(-1);
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowUp') {
                            event.preventDefault();
                            step(1);
                        } else if (event.key === 'ArrowDown') {
                            event.preventDefault();
                            step(-1);
                        }
                    }}
                    aria-label="Command"
                    aria-invalid={problem !== null}
                    aria-describedby="command-bar-hint"
                    autoComplete="off"
                    autoCapitalize="off"
                    autoCorrect="off"
                    spellCheck={false}
                    placeholder={
                        placeholder ? formatArgv(placeholder) : 'ls -la'
                    }
                    className="h-9 min-w-0 flex-1 bg-transparent type-mono outline-none placeholder:text-faint disabled:cursor-not-allowed"
                />
                <Button
                    type="submit"
                    size="sm"
                    disabled={disabled || empty || problem !== null || busy}
                >
                    {busy ? <Spinner /> : <CornerDownLeft aria-hidden="true" />}
                    Run
                </Button>
            </form>
            <div
                id="command-bar-hint"
                className="flex flex-wrap items-center gap-x-4 gap-y-2 border-t px-4 py-2.5"
            >
                {message ? (
                    <p
                        role="alert"
                        className="min-w-0 flex-1 basis-64 type-small text-status-error"
                    >
                        {message}
                    </p>
                ) : disabled ? (
                    <p className="flex min-w-0 flex-1 basis-64 items-start gap-2 type-small text-muted-foreground">
                        <Info
                            aria-hidden="true"
                            className="mt-0.5 size-3.5 shrink-0"
                        />
                        {blocked}
                    </p>
                ) : (
                    <p className="min-w-0 flex-1 basis-64 type-small text-muted-foreground">
                        Runs in a new container from the live version, with this
                        service’s variables and volumes.
                    </p>
                )}
                {!disabled && timeouts.length > 1 && (
                    <label className="flex shrink-0 items-center gap-2 type-small text-muted-foreground">
                        Stops after
                        <Select
                            value={String(timeout)}
                            onValueChange={(next) =>
                                onTimeoutChange(Number(next))
                            }
                        >
                            <SelectTrigger
                                size="sm"
                                className="h-7 gap-1 px-2 type-small text-foreground"
                                aria-label="Stops after"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent align="end">
                                {timeouts.map((seconds) => (
                                    <SelectItem
                                        key={seconds}
                                        value={String(seconds)}
                                    >
                                        {timeoutLabel(seconds)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </label>
                )}
            </div>
        </div>
    );
});
