import { project as projectDeployments } from '@/routes/deployments';
import {
    activity,
    analyticsPage,
    canvas,
    logs,
    previews,
    resourcesPage,
    settings,
    show,
} from '@/routes/projects';
import { show as stagedChanges } from '@/routes/staged-changes';

/** Pages that exist once per environment: switching environments keeps them. */
const ENVIRONMENT_PAGES: Partial<
    Record<
        string,
        (project: string, environment: string, url?: string) => string
    >
> = {
    'projects/logs': (project, environment) => logs([project, environment]).url,
    // Analytics keeps its view (Traffic or Resources).
    'projects/analytics': (project, environment, url) =>
        (/\/analytics\/resources(?:[?#]|$)/.test(url ?? '')
            ? resourcesPage
            : analyticsPage)([project, environment]).url,
    'projects/changes': (project, environment) =>
        stagedChanges({ project, environment }).url,
};

/** Pages of every project: switching projects keeps them. */
const PROJECT_PAGES: Partial<
    Record<string, (project: string, url: string) => string>
> = {
    'projects/deployments/index': (project) => projectDeployments(project).url,
    'projects/previews': (project) => previews(project).url,
    'projects/activity': (project) => activity(project).url,
    // Every project has the same settings pages: keep the open one.
    'projects/settings': (project, url) => {
        const section = /\/settings\/([a-z-]+)/.exec(url)?.[1];

        return settings({ project, ...(section ? { section } : {}) }).url;
    },
};

/**
 * Where the environment switcher goes (docs/08 §1): the same page in the
 * other environment where it has one (logs, analytics in its view, staged
 * changes), else its canvas. Query strings (a service filter, a range)
 * belong to the old environment and are dropped.
 */
export function environmentSwitchHref(
    component: string,
    project: string,
    environment: string,
    url?: string,
): string {
    const page = ENVIRONMENT_PAGES[component];

    return page
        ? page(project, environment, url)
        : canvas([project, environment]).url;
}

/**
 * Where the project switcher goes: the same page in the other project
 * (deployments, previews, activity, settings, and logs or analytics of
 * its production environment), else its production canvas,
 * linked directly instead of through the `/p/{project}` redirect.
 */
export function projectSwitchHref(
    component: string,
    url: string,
    target: App.Data.Canvas.ProjectLinkData,
): string {
    const projectPage = PROJECT_PAGES[component];

    if (projectPage) {
        return projectPage(target.id, url);
    }

    if (!target.environmentId) {
        return show(target.id).url;
    }

    const environmentPage = ENVIRONMENT_PAGES[component];

    return environmentPage && component !== 'projects/changes'
        ? environmentPage(target.id, target.environmentId, url)
        : canvas([target.id, target.environmentId]).url;
}
