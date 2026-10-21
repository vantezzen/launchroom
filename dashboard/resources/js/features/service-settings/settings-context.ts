export type SettingsContext = {
    route: [project: string, environment: string, service: string];
    node: App.Data.Canvas.CanvasNodeData;
    settings: App.Data.Canvas.ServiceSettingsData;
    canUpdate: boolean;
    canDelete: boolean;
};
