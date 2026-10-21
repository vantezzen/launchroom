import type { Metadata } from 'next';
import { Screenshot } from '@/components/screenshot';
import { FactGrid, FeatureFooter, FeatureHero } from '@/components/marketing/feature-page';
import { FeatureRow, Section, SectionHeading } from '@/components/marketing/section';
import { ServerView } from '@/components/marketing/showcase';

export const metadata: Metadata = {
    title: 'Logs and metrics',
    description:
        'Server load, CPU per service, request metrics with deploy markers, searchable logs and alerts. Built in, on your own server.',
    alternates: { canonical: '/features/observability' },
};

export default function Page() {
    return (
        <>
            <FeatureHero
                label="Logs and metrics"
                title="See what every release did."
                lead="launchroom measures requests where traffic enters, marks every deploy on the charts and keeps the logs of every service searchable. All of it runs on your server, with nothing to install."
                docsHref="/docs/observability/metrics"
                visual={
                    <Screenshot
                        id="metrics"
                        preload
                        caption={false}
                        sizes="(min-width: 1200px) 1180px, 100vw"
                        frameless
                    />
                }
            />
            <Section tone="frame" labelledBy="servers-title">
                <SectionHeading id="servers-title" label="Servers" title="All the load, in one view.">
                    The servers page shows the combined load of your servers, where they are and how they connect. Each
                    server page lists the services running on it with their CPU and memory, so you see which one takes
                    how much.
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
                    visual={<Screenshot id="alerts" caption={false} sizes="(min-width: 1024px) 700px, 100vw" />}
                    points={[
                        'Failed deploys, unreachable servers, error rates, full disks, failed backups',
                        'Email, Slack, Discord and signed webhooks',
                        'One message per problem, and one when it is resolved',
                    ]}
                    more={{ href: '/docs/observability/alerts', text: 'Alerts' }}
                >
                    Set the thresholds once per team. launchroom groups repeated alerts and tells you when things are
                    back to normal.
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
                            title: 'Measured at the edge',
                            text: 'Requests, status codes, latency percentiles and bandwidth per route.',
                        },
                        {
                            title: 'Private by default',
                            text: 'Visitor addresses and paths are removed before a log line leaves the proxy.',
                        },
                        {
                            title: 'Usage per service',
                            text: 'CPU and memory per service, on the canvas and on each server page.',
                        },
                        { title: 'Link quality', text: 'Latency and bandwidth between every pair of servers.' },
                        { title: 'Activity', text: 'Deploys, promotions and changes in one timeline.' },
                        { title: 'CLI', text: 'Follow logs from your terminal or CI with the launchroom CLI.' },
                    ]}
                />
            </Section>
            <FeatureFooter current="/features/observability" />
        </>
    );
}
