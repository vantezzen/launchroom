import { useId } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';

/**
 * "Detected + Override" as a settings row (design-system §6, §13.9): the
 * label, then what launchroom detected in muted mono ("Detected: npm ci"),
 * and an Override switch right. On, an input with the value replaces the
 * detected line; off again resets to the detected value.
 */
export function OverrideRow({
    label,
    detected,
    detectedFallback = 'Detected on the next build',
    value,
    onChange,
    disabled,
    error,
    helper,
    type = 'text',
    placeholder,
    mono = true,
    source = 'Detected',
    setting,
}: {
    label: string;
    detected: string | null;
    detectedFallback?: string;
    /** null = not overridden. */
    value: string | null;
    onChange: (value: string | null) => void;
    disabled?: boolean;
    error?: string;
    helper?: ReactNode;
    type?: 'text' | 'number';
    placeholder?: string;
    mono?: boolean;
    /** The word before the detected value ("Detected", "From production"). */
    source?: string;
    /** Search anchor (`settings-index.ts`). */
    setting?: string;
}) {
    const id = useId();
    const overridden = value !== null;

    return (
        <div data-setting={setting} className="flex flex-col gap-3 px-5 py-4">
            <div className="flex items-start justify-between gap-6">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <label
                        htmlFor={overridden ? id : undefined}
                        className="type-body font-medium"
                    >
                        {label}
                    </label>
                    {!overridden && (
                        <p className="flex min-w-0 flex-wrap items-baseline gap-x-1.5 type-small text-muted-foreground">
                            {detected !== null ? (
                                <>
                                    <span>{source}</span>
                                    <code className="min-w-0 type-mono-small break-all text-foreground">
                                        {detected}
                                    </code>
                                </>
                            ) : (
                                <span>{detectedFallback}</span>
                            )}
                        </p>
                    )}
                    {overridden && helper && (
                        <p className="type-small text-muted-foreground">
                            {helper}
                        </p>
                    )}
                </div>
                <label className="flex shrink-0 items-center gap-2 type-small text-muted-foreground">
                    Override
                    <Switch
                        checked={overridden}
                        disabled={disabled}
                        aria-label={`Override ${label.toLowerCase()}`}
                        onCheckedChange={(checked) =>
                            onChange(checked ? (detected ?? '') : null)
                        }
                    />
                </label>
            </div>
            {overridden && (
                <div className="flex flex-col gap-1 motion-safe:animate-in motion-safe:duration-base motion-safe:fade-in-0">
                    <Input
                        id={id}
                        type={type}
                        value={value}
                        disabled={disabled}
                        placeholder={placeholder ?? detected ?? undefined}
                        onChange={(event) => onChange(event.target.value)}
                        className={mono ? 'font-mono text-[13px]' : undefined}
                        aria-invalid={error ? true : undefined}
                        autoComplete="off"
                        spellCheck={false}
                    />
                    <InputError message={error} />
                </div>
            )}
            {!overridden && <InputError message={error} />}
        </div>
    );
}
