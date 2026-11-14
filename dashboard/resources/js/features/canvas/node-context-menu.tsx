import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { LIFECYCLE_CONSEQUENCE } from '@/features/service-panel/lifecycle';

export type NodeMenuAction =
    | 'open'
    | 'settings'
    | 'variables'
    | 'logs'
    | 'analytics'
    | 'redeploy'
    | 'restart'
    | 'copy-private'
    | 'delete';

/**
 * Right-click (or Shift+F10 / context-menu key) menu of a canvas node,
 * anchored at the pointer through an invisible trigger.
 */
export function NodeContextMenu({
    node,
    position,
    canDeploy,
    canManage,
    onAction,
    onClose,
}: {
    node: App.Data.Canvas.CanvasNodeData;
    position: { x: number; y: number };
    canDeploy: boolean;
    canManage: boolean;
    onAction: (action: NodeMenuAction) => void;
    onClose: () => void;
}) {
    const deployed = node.status.deploymentId !== null && !node.removed;
    const stopped = node.status.state === 'idle';

    return (
        <DropdownMenu open onOpenChange={(open) => !open && onClose()}>
            <DropdownMenuTrigger asChild>
                <span
                    aria-hidden="true"
                    className="pointer-events-none fixed size-px"
                    style={{ left: position.x, top: position.y }}
                />
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="w-56"
                aria-label={`${node.name} actions`}
                onCloseAutoFocus={(event) => event.preventDefault()}
            >
                <DropdownMenuItem onSelect={() => onAction('open')}>
                    Open
                    <DropdownMenuShortcut>Enter</DropdownMenuShortcut>
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => onAction('logs')}>
                    Logs
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => onAction('analytics')}>
                    Analytics
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => onAction('variables')}>
                    Variables
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => onAction('settings')}>
                    Settings
                </DropdownMenuItem>
                {node.privateAddress && (
                    <DropdownMenuItem onSelect={() => onAction('copy-private')}>
                        Copy private address
                    </DropdownMenuItem>
                )}
                {canDeploy && deployed && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onSelect={() => onAction('redeploy')}>
                            Redeploy…
                        </DropdownMenuItem>
                        {!stopped && (
                            <DropdownMenuItem
                                onSelect={() => onAction('restart')}
                            >
                                Restart…
                            </DropdownMenuItem>
                        )}
                    </>
                )}
                {canManage && !node.removed && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => onAction('delete')}
                        >
                            Remove from environment
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * The confirmation after Redeploy or Restart in the node menu
 * (design-system §6: reversible but consequential, so a confirm popover
 * with the consequence), anchored where the menu was.
 */
export function NodeActionConfirm({
    node,
    kind,
    position,
    onConfirm,
    onClose,
}: {
    node: App.Data.Canvas.CanvasNodeData;
    kind: 'redeploy' | 'restart';
    position: { x: number; y: number };
    onConfirm: () => void | Promise<unknown>;
    onClose: () => void;
}) {
    const [busy, setBusy] = useState(false);
    const confirm = async () => {
        setBusy(true);

        try {
            await onConfirm();
        } finally {
            setBusy(false);
            onClose();
        }
    };

    return (
        <Popover open onOpenChange={(open) => !open && onClose()}>
            <PopoverAnchor asChild>
                <span
                    aria-hidden="true"
                    className="pointer-events-none fixed size-px"
                    style={{ left: position.x, top: position.y }}
                />
            </PopoverAnchor>
            <PopoverContent align="start" className="flex w-80 flex-col gap-3">
                <div className="flex flex-col gap-1">
                    <p className="type-label">
                        {kind === 'redeploy'
                            ? `Redeploy ${node.name}?`
                            : `Restart ${node.name}?`}
                    </p>
                    <p className="type-body text-muted-foreground">
                        {LIFECYCLE_CONSEQUENCE[kind]}
                    </p>
                </div>
                <div className="flex justify-end gap-2">
                    <Button variant="ghost" size="sm" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        size="sm"
                        disabled={busy}
                        onClick={() => void confirm()}
                    >
                        {kind === 'redeploy' ? 'Redeploy' : 'Restart'}
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
