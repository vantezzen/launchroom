import type { Node, NodeProps } from '@xyflow/react';
import { memo } from 'react';
import { GROUPS } from './groups';
import type { GroupId } from './groups';

export type GroupFrameData = { group: GroupId; count: number };

export type GroupFlowNode = Node<GroupFrameData, 'group-frame'>;

/**
 * A group frame behind its services (Laravel Cloud environment groups):
 * 18 px radius, a 3 % foreground wash, hairline, label top-left with icon
 * and count. Not interactive; it follows the nodes.
 */
export const GroupFrame = memo(function GroupFrame({
    data,
    width,
    height,
}: NodeProps<GroupFlowNode>) {
    const group = GROUPS.find((item) => item.id === data.group);

    if (!group) {
        return null;
    }

    return (
        <div
            aria-hidden="true"
            className="pointer-events-none rounded-[18px] border border-border bg-foreground/[0.025]"
            style={{ width, height }}
        >
            <span className="flex items-center gap-1.5 px-4 pt-3 type-small text-muted-foreground">
                <group.icon aria-hidden="true" className="size-3.5" />
                {group.label}
                <span className="tabular-nums">· {data.count}</span>
            </span>
        </div>
    );
});
