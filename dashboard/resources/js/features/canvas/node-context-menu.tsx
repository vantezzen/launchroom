import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export type NodeMenuAction =
    | 'open'
    | 'settings'
    | 'variables'
    | 'redeploy'
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
                            Redeploy
                        </DropdownMenuItem>
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
