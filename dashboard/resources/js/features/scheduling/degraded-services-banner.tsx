import { PlacementIssueBanner } from './placement-issue-banner';

/**
 * Above the canvas: every service of the environment that isn't fully
 * placed (its stateful server is down, or replicas found no other server).
 * Never hide a single point of failure (docs/02 §5).
 */
export function DegradedServicesBanner({
    nodes,
    backupsHref,
}: {
    nodes: App.Data.Canvas.CanvasNodeData[];
    backupsHref: (serviceId: string) => string;
}) {
    const degraded = nodes.filter((node) => node.placementIssue !== null);

    if (degraded.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2 border-b px-4 py-3">
            {degraded.map(
                (node) =>
                    node.placementIssue && (
                        <PlacementIssueBanner
                            key={node.id}
                            issue={node.placementIssue}
                            serviceName={node.name}
                            backupsHref={
                                node.kind === 'database'
                                    ? backupsHref(node.id)
                                    : undefined
                            }
                        />
                    ),
            )}
        </div>
    );
}
