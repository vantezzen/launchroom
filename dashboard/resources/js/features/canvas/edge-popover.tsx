import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import connections from '@/routes/projects/connections';
import { FloatingPanel } from './floating-panel';

const styleNames: Record<App.Enums.ConnectionStyle, string> = {
    url: 'one URL',
    laravel: 'Laravel names',
    parts: 'separate parts',
};

/**
 * Details of an existing connection: what it injects, and removing it
 * (staged; its variables go with it).
 */
export function EdgePopover({
    project,
    environment,
    edge,
    from,
    to,
    anchor,
    canDelete,
    onClose,
}: {
    project: string;
    environment: string;
    edge: App.Data.Canvas.CanvasEdgeData;
    from: string;
    to: string;
    anchor: { x: number; y: number };
    canDelete: boolean;
    onClose: () => void;
}) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    const remove = () => {
        setProcessing(true);
        router.delete(
            connections.destroy([project, environment, edge.id]).url,
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: onClose,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <FloatingPanel
            anchor={anchor}
            label={`Connection from ${from} to ${to}`}
            onClose={onClose}
        >
            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <h2 className="type-heading">
                        {from} → {to}
                    </h2>
                    <p className="type-small text-muted-foreground">
                        {edge.mode === 'public'
                            ? 'Through the public URL'
                            : 'Over the private network'}
                        {edge.style && `, ${styleNames[edge.style]}`}
                        {edge.staged &&
                            '. Staged: applies with the next deploy.'}
                    </p>
                </div>

                <ul
                    aria-label="Injected variables"
                    className="flex flex-col divide-y rounded-md border"
                >
                    {edge.variables.map((key) => (
                        <li key={key} className="px-3 py-2 type-mono">
                            {key}
                        </li>
                    ))}
                </ul>

                {canDelete && (
                    <div className="flex items-center justify-end gap-2">
                        {confirming ? (
                            <>
                                <span className="mr-auto type-small text-muted-foreground">
                                    Removes {edge.variables.length}{' '}
                                    {edge.variables.length === 1
                                        ? 'variable'
                                        : 'variables'}{' '}
                                    from {from}.
                                </span>
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => setConfirming(false)}
                                >
                                    Keep
                                </Button>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    disabled={processing}
                                    onClick={remove}
                                >
                                    Remove
                                </Button>
                            </>
                        ) : (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setConfirming(true)}
                            >
                                Remove connection
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </FloatingPanel>
    );
}
