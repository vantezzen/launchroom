import { Link, router } from '@inertiajs/react';
import type { PageProps } from '@inertiajs/core';
import type { ComponentProps, MouseEvent } from 'react';
import { deploymentUrl } from './deployment-actions';
import { deploymentCacheTags } from './service-deployments';

type Summary = App.Data.Deployments.DeploymentSummaryData;
type Detail = App.Data.Deployments.DeploymentDetailData;

/** What the row knows beyond the summary: the expanded row's steps, the service logo. */
export type DeploymentPreview = {
    steps?: App.Data.Deployments.DeploymentStepData[];
    expectsRelease?: boolean;
    logo?: string;
};

/** The project shell (ProjectScope::shellProps) the deployment page shares with every project page. */
const SHELL_PROPS = [
    'project',
    'environment',
    'environments',
    'projects',
    'abilities',
    'edgeOverview',
];

/**
 * The deployment page drawn from a row (docs/10 §3, instant visit): the
 * header, the overview and the steps come from the summary; the server's
 * answer fills in the rest and the lines. Everything the current page
 * shares with the deployment page (shared props, the project shell) is
 * carried over.
 */
export function instantDeploymentProps(
    current: PageProps,
    shared: Partial<PageProps>,
    deployment: Summary,
    preview: DeploymentPreview = {},
): Record<string, unknown> {
    const shell = Object.fromEntries(
        Object.entries(current).filter(([key]) => SHELL_PROPS.includes(key)),
    );
    const project = current.project as { name?: string } | undefined;
    const detail: Detail = {
        deployment,
        projectName: project?.name ?? '',
        revision: null,
        steps: preview.steps ?? [],
        failure: null,
        config: [],
        env: [],
        canRedeploy: false,
        canCancel: false,
        canPromote: false,
        failureExplanation: null,
        buildHistory: [],
        logo: preview.logo ?? 'app',
        builtOn: null,
        serving: null,
        previous: null,
        compareUrl: null,
        configErrors: null,
        buildServer: null,
        servers: [],
        supersededBy: null,
        expectsRelease: preview.expectsRelease ?? false,
    };

    return {
        ...shell,
        ...shared,
        detail,
        logs: { lines: [], hasMore: false, cursor: 0 },
        pending: true,
    };
}

function isPlainClick(event: MouseEvent) {
    return (
        !event.defaultPrevented &&
        event.button === 0 &&
        !event.metaKey &&
        !event.ctrlKey &&
        !event.shiftKey &&
        !event.altKey
    );
}

/**
 * A link to a deployment's page. Hovering prefetches it (and `eager` rows,
 * the one people open, prefetch when they appear); a click on a page that
 * isn't loaded yet opens it at once from the row's data instead of
 * waiting, and the lines stream in.
 */
export function DeploymentLink({
    deployment,
    preview,
    eager = false,
    onClick,
    ...props
}: Omit<ComponentProps<typeof Link>, 'href' | 'prefetch'> & {
    deployment: Summary;
    preview?: DeploymentPreview;
    eager?: boolean;
}) {
    const href = deploymentUrl(deployment);

    return (
        <Link
            {...props}
            href={href}
            prefetch={eager ? ['mount', 'hover'] : 'hover'}
            cacheFor={['5s', '1m']}
            cacheTags={deploymentCacheTags(deployment)}
            onClick={(event: MouseEvent) => {
                onClick?.(event);

                if (
                    !isPlainClick(event) ||
                    router.getCached(href) !== null ||
                    router.getPrefetching(href) !== null
                ) {
                    return;
                }

                event.preventDefault();
                router.visit(href, {
                    component: 'projects/deployments/show',
                    pageProps: (current, shared) =>
                        instantDeploymentProps(
                            current,
                            shared,
                            deployment,
                            preview,
                        ),
                });
            }}
        />
    );
}
