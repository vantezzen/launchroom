import { ChevronRight, Plus } from 'lucide-react';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import { compactAge } from './format';
import { GROUPS, groupOf } from './groups';
import { ServiceGlyph } from './service-glyph';
import { StagedChip } from './service-node';

/**
 * The canvas on phones (design-system §4.3): services as cards grouped
 * Public / App / Data; a tap opens the panel full screen. Doubles as the
 * accessible alternative to the canvas.
 */
export function CanvasList({
    nodes,
    onOpen,
    onAdd,
}: {
    nodes: App.Data.Canvas.CanvasNodeData[];
    onOpen: (serviceId: string) => void;
    /** The phone's way to add (the floating toolbar is desktop only). */
    onAdd?: () => void;
}) {
    return (
        <div className="flex flex-col gap-8 overflow-y-auto px-4 pt-5 pb-24">
            {GROUPS.map((group) => {
                const members = nodes.filter(
                    (node) => groupOf(node) === group.id,
                );

                if (members.length === 0) {
                    return null;
                }

                return (
                    <section key={group.id} className="flex flex-col gap-3">
                        <h2 className="flex items-center gap-1.5 type-small text-muted-foreground">
                            <group.icon
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            {group.label}
                        </h2>
                        <ul className="flex flex-col gap-2">
                            {members.map((node) => (
                                <li key={node.id}>
                                    <button
                                        type="button"
                                        onClick={() => onOpen(node.id)}
                                        className="flex min-h-16 w-full items-center gap-3 rounded-[14px] border bg-card p-3.5 text-left shadow-card focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        <ServiceGlyph logo={node.logo} />
                                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                            <span className="flex items-center gap-2">
                                                <span className="truncate type-label">
                                                    {node.name}
                                                </span>
                                                {node.staged && (
                                                    <StagedChip
                                                        effect={node.staged}
                                                    />
                                                )}
                                            </span>
                                            <Status
                                                state={node.status.state}
                                                label={node.status.label}
                                                detail={
                                                    node.status.since
                                                        ? compactAge(
                                                              node.status.since,
                                                          )
                                                        : undefined
                                                }
                                                className="type-small"
                                            />
                                        </span>
                                        <ChevronRight
                                            aria-hidden="true"
                                            className="size-4 shrink-0 text-muted-foreground"
                                        />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </section>
                );
            })}
            {onAdd && (
                <Button
                    variant="outline"
                    className="h-11 w-full"
                    onClick={onAdd}
                >
                    <Plus aria-hidden="true" />
                    Add a service
                </Button>
            )}
        </div>
    );
}
