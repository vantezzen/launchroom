type ShellKey = keyof App.Data.Canvas.ProjectShellPropsData;

/** A record so the list stops compiling when ProjectShellPropsData gains or loses a prop. */
const keys: Record<ShellKey, true> = {
    project: true,
    environment: true,
    environments: true,
    projects: true,
    abilities: true,
    edgeOverview: true,
    projectStatuses: true,
    environmentHealth: true,
    environmentStatuses: true,
};

/** The props every project page shares (App\Queries\ProjectShell::props). */
export const projectShellKeys = Object.keys(keys) as ShellKey[];
