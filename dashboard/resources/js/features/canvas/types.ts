import type { Edge, Node } from '@xyflow/react';

export type ServiceNodeData = App.Data.Canvas.CanvasNodeData & {
    connectable: boolean;
};

export type ServiceFlowNode = Node<ServiceNodeData, 'service'>;

export type ConnectionEdgeData = App.Data.Canvas.CanvasEdgeData & {
    onOpen?: (edgeId: string, anchor: { x: number; y: number }) => void;
    /** Previews: the target database is shared with the base or cloned (docs/06 §5). */
    badge?: App.Data.Previews.PreviewEdgeBadgeData;
};

export type ConnectionFlowEdge = Edge<ConnectionEdgeData, 'connection'>;
