import type { LucideIcon } from 'lucide-react';
import { Boxes, Database, Globe } from 'lucide-react';

/**
 * Automatic canvas groups (direction §9.4, Laravel Cloud environment):
 * Public (services with an address), App (workers, crons, internal
 * services) and Data (databases). Derived from the nodes; nothing stored.
 */
export type GroupId = 'public' | 'app' | 'data';

export const GROUPS: {
    id: GroupId;
    label: string;
    icon: LucideIcon;
}[] = [
    { id: 'public', label: 'Public', icon: Globe },
    { id: 'app', label: 'App', icon: Boxes },
    { id: 'data', label: 'Data', icon: Database },
];

export function groupOf(node: App.Data.Canvas.CanvasNodeData): GroupId {
    if (node.kind === 'database') {
        return 'data';
    }

    return node.domain !== null &&
        node.kind !== 'worker' &&
        node.kind !== 'cron'
        ? 'public'
        : 'app';
}

export function groupIndex(group: GroupId): number {
    return GROUPS.findIndex((item) => item.id === group);
}

export type Frame = {
    id: GroupId;
    x: number;
    y: number;
    width: number;
    height: number;
    count: number;
};

const PAD = 24;
const LABEL = 34;

/**
 * Group frames around their nodes' bounding boxes. When frames would
 * overlap (the user arranged services across groups) none are drawn: a
 * wrong frame is worse than none.
 */
export function framesFor(
    nodes: {
        id: string;
        group: GroupId;
        x: number;
        y: number;
        width: number;
        height: number;
    }[],
): Frame[] {
    const frames: Frame[] = [];

    for (const group of GROUPS) {
        const members = nodes.filter((node) => node.group === group.id);

        if (members.length === 0) {
            continue;
        }

        const minX = Math.min(...members.map((node) => node.x));
        const minY = Math.min(...members.map((node) => node.y));
        const maxX = Math.max(...members.map((node) => node.x + node.width));
        const maxY = Math.max(...members.map((node) => node.y + node.height));

        frames.push({
            id: group.id,
            x: minX - PAD,
            y: minY - PAD - LABEL,
            width: maxX - minX + PAD * 2,
            height: maxY - minY + PAD * 2 + LABEL,
            count: members.length,
        });
    }

    for (const a of frames) {
        for (const b of frames) {
            if (
                a !== b &&
                a.x < b.x + b.width &&
                b.x < a.x + a.width &&
                a.y < b.y + b.height &&
                b.y < a.y + a.height
            ) {
                return [];
            }
        }
    }

    // Only worth drawing when there is something to separate.
    return frames.length > 1 ? frames : [];
}
