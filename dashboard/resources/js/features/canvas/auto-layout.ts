import ELK from 'elkjs/lib/elk.bundled.js';

export const NODE_WIDTH = 264;
export const NODE_HEIGHT = 148;

const elk = new ELK();

/**
 * Layered left-to-right layout: consumers left of what they connect to
 * (web → api → postgres), see docs/08. With
 * `partition` (the canvas group index: Public 0, App 1, Data 2) the groups
 * become columns instead (groupedLayout), so their frames never overlap.
 */
export async function autoLayout(
    nodes: { id: string; partition?: number }[],
    edges: { id: string; source: string; target: string }[],
): Promise<Record<string, { x: number; y: number }>> {
    if (nodes.some((node) => node.partition !== undefined)) {
        return groupedLayout(nodes, edges);
    }

    const ids = new Set(nodes.map((node) => node.id));
    const graph = await elk.layout({
        id: 'root',
        layoutOptions: {
            'elk.algorithm': 'layered',
            'elk.direction': 'RIGHT',
            'elk.spacing.nodeNode': '56',
            'elk.layered.spacing.nodeNodeBetweenLayers': '136',
            'elk.layered.nodePlacement.strategy': 'BRANDES_KOEPF',
            'elk.separateConnectedComponents': 'true',
            'elk.spacing.componentComponent': '72',
        },
        children: nodes.map((node) => ({
            id: node.id,
            width: NODE_WIDTH,
            height: NODE_HEIGHT,
        })),
        edges: edges
            .filter((edge) => ids.has(edge.source) && ids.has(edge.target))
            .map((edge) => ({
                id: edge.id,
                sources: [edge.source],
                targets: [edge.target],
            })),
    });

    return Object.fromEntries(
        (graph.children ?? []).map((child) => [
            child.id,
            { x: Math.round(child.x ?? 0), y: Math.round(child.y ?? 0) },
        ]),
    );
}

const COLUMN_GAP = 40;
const ROW_GAP = 40;
const GROUP_GAP = 136;
const PER_COLUMN = 4;

/**
 * Groups as columns, left to right (Public → App → Data, Laravel Cloud
 * environment): each group stacks its services (up to four per column),
 * level with what they connect to in the columns before; columns that
 * connect to nothing are centred. Frames around them never overlap.
 */
function groupedLayout(
    nodes: { id: string; partition?: number }[],
    edges: { source: string; target: string }[],
): Record<string, { x: number; y: number }> {
    const partitions = [
        ...new Set(nodes.map((node) => node.partition ?? 0)),
    ].sort((a, b) => a - b);
    const neighbours = new Map<string, string[]>();

    for (const edge of edges) {
        neighbours.set(edge.source, [
            ...(neighbours.get(edge.source) ?? []),
            edge.target,
        ]);
        neighbours.set(edge.target, [
            ...(neighbours.get(edge.target) ?? []),
            edge.source,
        ]);
    }

    const positions: Record<string, { x: number; y: number }> = {};
    const step = NODE_HEIGHT + ROW_GAP;
    let x = 0;
    let tallest = 0;
    const unanchored: { ids: string[]; height: number }[] = [];

    for (const partition of partitions) {
        // Where each member would like to sit: level with what it connects
        // to in the columns already placed (fewer crossings, labels visible).
        const members = nodes
            .filter((node) => (node.partition ?? 0) === partition)
            .map((node, index) => {
                const ys = (neighbours.get(node.id) ?? [])
                    .map((id) => positions[id]?.y)
                    .filter((value): value is number => value !== undefined);

                return {
                    id: node.id,
                    index,
                    want:
                        ys.length > 0
                            ? ys.reduce((sum, value) => sum + value, 0) /
                              ys.length
                            : null,
                };
            })
            .sort(
                (a, b) =>
                    (a.want ?? Number.MAX_SAFE_INTEGER) -
                        (b.want ?? Number.MAX_SAFE_INTEGER) ||
                    a.index - b.index,
            );
        const anchored = members.some((member) => member.want !== null);
        const columns = Math.ceil(members.length / PER_COLUMN);
        const ids: string[] = [];
        let bottom = 0;

        members.forEach((member, index) => {
            const sub = Math.floor(index / PER_COLUMN);
            const row = index % PER_COLUMN;
            const min = row === 0 ? 0 : bottom + ROW_GAP;
            const top = Math.max(min, member.want ?? min);

            positions[member.id] = {
                x: x + sub * (NODE_WIDTH + COLUMN_GAP),
                y: Math.round(top),
            };
            bottom = top + NODE_HEIGHT;
            tallest = Math.max(tallest, bottom);
            ids.push(member.id);
        });

        if (!anchored && x > 0) {
            unanchored.push({
                ids,
                height: Math.min(members.length, PER_COLUMN) * step - ROW_GAP,
            });
        }

        x += columns * NODE_WIDTH + (columns - 1) * COLUMN_GAP + GROUP_GAP;
    }

    // Columns that connect to nothing placed sit centred next to the rest.
    for (const column of unanchored) {
        const offset = Math.round((tallest - column.height) / 2);

        for (const id of column.ids) {
            positions[id] = { ...positions[id], y: positions[id].y + offset };
        }
    }

    return positions;
}
