export type SettingsContext = {
    route: [project: string, environment: string, service: string];
    node: App.Data.Canvas.CanvasNodeData;
    settings: App.Data.Canvas.ServiceSettingsData;
    canUpdate: boolean;
    canDelete: boolean;
    /** Newer releases of an image service (D106); null for other kinds. */
    update?: App.Data.AppUpdates.ServiceUpdateData | null;
};
