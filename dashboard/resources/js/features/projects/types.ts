/**
 * Props shared by every project page (App\Http\Controllers\Projects\ProjectScope::shellProps).
 */
export type ProjectShellProps = {
    project: App.Data.Canvas.ProjectData;
    environment: App.Data.Canvas.EnvironmentData | null;
    environments: App.Data.Canvas.EnvironmentData[];
    projects: App.Data.Canvas.ProjectLinkData[];
    abilities: App.Data.Canvas.ProjectAbilitiesData;
    /** Lazy shell prop: single point of failure badge (docs/02 §5). */
    edgeOverview?: App.Data.Ingress.EdgeOverviewData;
};
