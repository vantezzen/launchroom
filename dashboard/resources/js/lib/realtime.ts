/**
 * Channel and event names of the browser broadcasts. The kinds and names
 * are the generated types of App\Broadcasting\Channel and BroadcastEvent;
 * payloads are the App\Data\Broadcasts types.
 */
export type ChannelKind = App.Broadcasting.Channel;
export type BroadcastName = App.Broadcasting.BroadcastEvent;

type Exhaustive<T extends Record<BroadcastName, unknown>> = T;

export type BroadcastPayloads = Exhaustive<{
    'canvas.changed': App.Data.Broadcasts.CanvasChangedData;
    'deployment.updated': App.Data.Broadcasts.DeploymentUpdatedData;
    'logs.appended': App.Data.Broadcasts.LogsAppendedData;
    'server.metrics': App.Data.Broadcasts.ServerMetricsData;
    'service.activity': App.Data.Broadcasts.ServiceActivityData;
    'security.changed': App.Data.Broadcasts.SecurityChangedData;
    'backups.changed': App.Data.Broadcasts.BackupsChangedData;
}>;

export type BroadcastPayload<E extends BroadcastName> = BroadcastPayloads[E];

export function channelName(kind: ChannelKind, publicId: string): string {
    return `${kind}.${publicId}`;
}

/** Echo's form of a custom `broadcastAs` name (a leading dot, no namespace). */
export function echoEvent(event: BroadcastName): `.${BroadcastName}` {
    return `.${event}`;
}
