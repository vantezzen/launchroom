import type { Metadata } from 'next';
import { Screenshot } from '@/components/screenshot';
import { FactGrid, FeatureFooter, FeatureHero } from '@/components/marketing/feature-page';
import { EdgeReduction, regions } from '@/components/marketing/insights';
import { FeatureRow, Section, SectionHeading } from '@/components/marketing/section';
import { ServerView } from '@/components/marketing/showcase';

export const metadata: Metadata = {
    title: 'Analytics and metrics',
    description:
        'Request analytics per service and project without a tracking script: requests by status, top paths, referrers and clients, counted on your own server. Plus server and service metrics, searchable logs and alerts.',
    alternates: { canonical: '/features/analytics' },
};

export default function Page() {
    return (
        <>
            <FeatureHero
                label="Analytics and metrics"
                title="See your traffic without a tracking script."
                lead="launchroom counts requests where they enter your server. Every service and project gets requests per hour by status, its top paths, referrers and clients, next to the load, logs and alerts of your servers. Nothing to install, nothing to add to your app."
                docsHref="/docs/observability/analytics"
                visual={
                    <Screenshot
                        id="analytics-service"
                        preload
                        caption={false}
                        sizes="(min-width: 1200px) 1180px, 100vw"
                        frameless
                    />
                }
            />
            <Section tone="frame" labelledBy="privacy-title">
                <FeatureRow
                    id="privacy-title"
                    label="Privacy"
                    title="Counted at the edge, without addresses or cookies."
                    visual={<EdgeReduction />}
                    points={[
                        'No IP addresses, locations, cookies or full user agents',
                        'Query strings are cut before the line leaves the proxy',
                        'IDs, UUIDs and tokens in paths become :id',
                        'No script in your pages and nothing to set up',
                    ]}
                    more={{ href: '/docs/observability/analytics', text: 'Analytics' }}
                >
                    The proxy in front of your apps already sees every request. launchroom reduces each one to its path,
                    status, the host of the referrer and whether it came from a browser or a bot, and counts them per 10
                    minutes on your server.
                </FeatureRow>
            </Section>
            <Section labelledBy="paths-title">
                <FeatureRow
                    id="paths-title"
                    label="Per service"
                    title="Which paths get the traffic, and which fail."
                    wide
                    visual={
                        <Screenshot
                            id="analytics-service"
                            caption={false}
                            region={regions.analyticsLists}
                            sizes="(min-width: 1200px) 1180px, 100vw"
                        />
                    }
                    points={[
                        'Top paths with their share of 4xx and 5xx responses',
                        'Referrers by host, and how much traffic comes from bots',
                        'Day, week or month, with the change against the period before',
                    ]}
                >
                    Open a project’s Analytics tab, or narrow it to one service. A broken route or a bot probing for
                    /wp-login.php stands out in the list.
                </FeatureRow>
            </Section>
            <Section tone="frame" labelledBy="team-title">
                <FeatureRow
                    id="team-title"
                    label="Team overview"
                    title="Every project in one view."
                    wide
                    visual={
                        <Screenshot
                            id="analytics-overview"
                            caption={false}
                            region={{ x: 272, y: 340, width: 1146, height: 880 }}
                            sizes="(min-width: 1200px) 1180px, 100vw"
                        />
                    }
                    points={[
                        'Requests per hour stacked by project',
                        'Share, change and error rate per project',
                        'The busiest paths across all projects',
                        'A 24-hour chart on every project card',
                    ]}
                    more={{ href: '/docs/observability/analytics', text: 'Analytics' }}
                >
                    Analytics in the sidebar adds up every project of your team. Select one to see its paths, then a
                    service.
                </FeatureRow>
            </Section>
            <Section labelledBy="metrics-title">
                <FeatureRow
                    id="metrics-title"
                    label="Metrics"
                    title="Latency and errors next to every deploy."
                    wide
                    visual={
                        <Screenshot
                            id="metrics"
                            caption={false}
                            region={{ x: 0, y: 0, width: 1440, height: 1030 }}
                            sizes="(min-width: 1200px) 1180px, 100vw"
                        />
                    }
                    points={[
                        'Requests, status codes, latency percentiles and bandwidth per route',
                        'CPU and memory per service and replica',
                        'A marker for every deploy on every chart',
                    ]}
                    more={{ href: '/docs/observability/metrics', text: 'Metrics' }}
                >
                    The Metrics tab shows how a service behaves: latency, errors and resources, with the deploy that
                    came before a change.
                </FeatureRow>
            </Section>
            <Section tone="frame" labelledBy="servers-title">
                <SectionHeading id="servers-title" label="Servers" title="All the load, in one view.">
                    The servers page shows the combined load of your servers, where they are and how they connect. Each
                    server page lists the services running on it with their CPU and memory.
                </SectionHeading>
                <ServerView className="mt-12" />
            </Section>
            <Section labelledBy="logs-title">
                <FeatureRow
                    id="logs-title"
                    label="Logs"
                    title="One search across every service."
                    visual={<Screenshot id="logs" caption={false} sizes="(min-width: 1024px) 700px, 100vw" />}
                    points={[
                        'Build and runtime logs, live or from the past',
                        'A histogram with deploy markers, filters by level, service and environment',
                        'Retention you choose per environment',
                    ]}
                    more={{ href: '/docs/observability/logs', text: 'Logs' }}
                >
                    Search a project, an environment or the whole team. The histogram shows when errors started, and the
                    deploy marker next to it shows which release came before.
                </FeatureRow>
            </Section>
            <Section tone="frame" labelledBy="alerts-title">
                <FeatureRow
                    id="alerts-title"
                    label="Alerts"
                    title="Hear about it where you work."
                    reverse
                    visual={
                        <Screenshot
                            id="alerts"
                            caption={false}
                            region={{ x: 0, y: 0, width: 1440, height: 1100 }}
                            sizes="(min-width: 1024px) 700px, 100vw"
                        />
                    }
                    points={[
                        'Failed deploys, unreachable servers, error rates, full disks, failed backups',
                        'Vulnerabilities that need action and a web firewall that stopped',
                        'Email, Slack, Discord and signed webhooks',
                    ]}
                    more={{ href: '/docs/observability/alerts', text: 'Alerts' }}
                >
                    Set the thresholds once per team. launchroom sends one message per problem and one when it is
                    resolved.
                </FeatureRow>
            </Section>
            <Section labelledBy="console-title">
                <FeatureRow
                    id="console-title"
                    label="Console"
                    title="A shell in a running container, in the browser."
                    visual={<Screenshot id="console" caption={false} sizes="(min-width: 1024px) 700px, 100vw" />}
                    more={{ href: '/docs/observability/console-and-data', text: 'Console and Data' }}
                >
                    Open a console in any replica as the container’s own user. Sessions are time-limited and recorded in
                    the audit log. There is no shell on the server itself.
                </FeatureRow>
            </Section>
            <Section tone="frame" labelledBy="facts-title">
                <h2 id="facts-title" className="sr-only">
                    Details
                </h2>
                <FactGrid
                    facts={[
                        {
                            title: 'Built in',
                            text: 'Analytics, metrics and logs run on your server. No external service, no extra container.',
                        },
                        {
                            title: 'Kept as long as you need',
                            text: 'Paths and referrers hourly for 7 days and daily for 90 days, both configurable.',
                        },
                        {
                            title: 'API',
                            text: 'Read the analytics of the team, an environment or a service with an API token.',
                        },
                        { title: 'Link quality', text: 'Latency and bandwidth between every pair of servers.' },
                        { title: 'Activity', text: 'Deploys, promotions and changes in one timeline.' },
                        { title: 'CLI', text: 'Follow logs from your terminal or CI with the launchroom CLI.' },
                    ]}
                />
            </Section>
            <FeatureFooter current="/features/analytics" />
        </>
    );
}
