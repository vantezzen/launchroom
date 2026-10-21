import { router } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { requestJson } from '@/features/projects/http';
import { cn } from '@/lib/utils';
import connections from '@/routes/projects/connections';
import { FloatingPanel } from './floating-panel';

const styleLabels: Record<App.Enums.ConnectionStyle, string> = {
    url: 'One URL',
    laravel: 'Laravel names',
    parts: 'Separate parts',
};

const modeLabels: Record<App.Enums.ConnectionMode, string> = {
    private: 'Private network',
    public: 'Public URL',
};

function Segmented<T extends string>({
    label,
    value,
    options,
    labels,
    onChange,
}: {
    label: string;
    value: T;
    options: T[];
    labels: Record<T, string>;
    onChange: (value: T) => void;
}) {
    return (
        <div className="grid gap-1.5">
            <span
                className="type-label text-muted-foreground"
                id={`seg-${label}`}
            >
                {label}
            </span>
            <div
                role="radiogroup"
                aria-labelledby={`seg-${label}`}
                className="inline-flex w-fit rounded-md border p-0.5"
            >
                {options.map((option) => (
                    <button
                        key={option}
                        type="button"
                        role="radio"
                        aria-checked={value === option}
                        className={cn(
                            'h-7 rounded-[4px] px-2.5 type-label transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                            value === option
                                ? 'bg-secondary text-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                        onClick={() => onChange(option)}
                    >
                        {labels[option]}
                    </button>
                ))}
            </div>
        </div>
    );
}

/**
 * The Connect popover after an edge drag (docs/06 §3.2): previews the
 * variables the connection injects into the source service, with
 * framework-aware naming, before anything is created (staged).
 */
export function ConnectPopover({
    project,
    environment,
    from,
    to,
    anchor,
    onClose,
}: {
    project: string;
    environment: string;
    from: App.Data.Canvas.CanvasNodeData;
    to: App.Data.Canvas.CanvasNodeData;
    anchor: { x: number; y: number };
    onClose: () => void;
}) {
    const [preview, setPreview] =
        useState<App.Data.Canvas.ConnectionPreviewData | null>(null);
    const [choice, setChoice] = useState<{
        mode?: App.Enums.ConnectionMode;
        style?: App.Enums.ConnectionStyle;
    }>({});
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        const controller = new AbortController();
        const query: Record<string, string> = { from: from.id, to: to.id };

        if (choice.mode) {
            query.mode = choice.mode;
        }

        if (choice.style) {
            query.style = choice.style;
        }

        requestJson<App.Data.Canvas.ConnectionPreviewData>(
            'get',
            connections.preview([project, environment], { query }).url,
            undefined,
            controller.signal,
        )
            .then((data) => {
                setPreview(data);
                setError(null);
            })
            .catch((failure: unknown) => {
                if (!controller.signal.aborted) {
                    setError(
                        failure instanceof Error
                            ? failure.message
                            : 'The preview failed.',
                    );
                }
            });

        return () => controller.abort();
    }, [project, environment, from.id, to.id, choice.mode, choice.style]);

    const connect = () => {
        if (!preview) {
            return;
        }

        setSubmitting(true);
        router.post(
            connections.store([project, environment]).url,
            {
                from: from.id,
                to: to.id,
                mode: preview.mode,
                style: preview.style,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: onClose,
                onError: (errors) =>
                    setError(Object.values(errors)[0] ?? 'Connecting failed.'),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const blocked = preview?.error ?? null;

    return (
        <FloatingPanel
            anchor={anchor}
            label={`Connect ${from.name} to ${to.name}`}
            onClose={onClose}
        >
            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <h2 className="type-heading">
                        Connect {from.name} to {to.name}
                    </h2>
                    <p className="type-small text-muted-foreground">
                        These variables are added to {from.name}. They reference{' '}
                        {to.name}, so they follow it in every environment.
                    </p>
                </div>

                {preview === null && error === null && (
                    <div className="flex items-center gap-2 type-body text-muted-foreground">
                        <Spinner className="size-4" />
                        Preparing the preview
                    </div>
                )}

                {preview && !blocked && (
                    <>
                        {preview.styles.length > 1 && (
                            <Segmented
                                label="Variable names"
                                value={preview.style}
                                options={preview.styles}
                                labels={styleLabels}
                                onChange={(style) =>
                                    setChoice((current) => ({
                                        ...current,
                                        style,
                                    }))
                                }
                            />
                        )}
                        {preview.modes.length > 1 && (
                            <Segmented
                                label="Reach it through"
                                value={preview.mode}
                                options={preview.modes}
                                labels={modeLabels}
                                onChange={(mode) =>
                                    setChoice((current) => ({
                                        ...current,
                                        mode,
                                    }))
                                }
                            />
                        )}
                        {preview.mode === 'public' && (
                            <p className="flex gap-2 type-small text-muted-foreground">
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="mt-0.5 size-3.5 shrink-0 text-status-progress"
                                />
                                Use the public URL only for values a browser
                                reads (NEXT_PUBLIC_*). Server-to-server traffic
                                is faster and private on the network.
                            </p>
                        )}
                        <ul
                            aria-label="Variables to inject"
                            className="flex flex-col divide-y rounded-md border"
                        >
                            {preview.variables.map((variable) => (
                                <li
                                    key={variable.key}
                                    className="flex flex-col gap-0.5 px-3 py-2"
                                >
                                    <span className="type-mono">
                                        {variable.key}
                                    </span>
                                    <span className="truncate type-mono-small text-muted-foreground">
                                        {variable.value}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                {(blocked ?? error) && (
                    <p role="alert" className="flex gap-2 type-body">
                        <TriangleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-status-error"
                        />
                        {blocked ?? error}
                    </p>
                )}

                <div className="flex justify-end gap-2">
                    <Button variant="secondary" size="sm" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        size="sm"
                        onClick={connect}
                        disabled={!preview || blocked !== null || submitting}
                    >
                        Connect
                    </Button>
                </div>
            </div>
        </FloatingPanel>
    );
}
