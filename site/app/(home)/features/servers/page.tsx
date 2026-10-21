import type { Metadata } from 'next';
import { Screenshot } from '@/components/screenshot';
import { ArchitectureDiagram } from '@/components/marketing/architecture';
import { FactGrid, FeatureFooter, FeatureHero } from '@/components/marketing/feature-page';
import { FeatureRow, Section, SectionHeading } from '@/components/marketing/section';
import { JoinMoment } from '@/components/marketing/moments';
import { ServerView } from '@/components/marketing/showcase';

export const metadata: Metadata = {
    title: 'Servers',
    description:
        'Run launchroom on one VPS and add more servers with one line each, including the machine at home. They connect themselves.',
    alternates: { canonical: '/features/servers' },
};

export default function Page() {
    return (
        <>
            <FeatureHero
                label="Servers"
                title="One VPS is enough. More join with one line."
                lead="launchroom runs the dashboard, builds, apps and databases on a single server. When you want more, run one command on the next machine and it joins."
                docsHref="/docs/servers/add-a-server"
                framed={false}
                visual={<ServerView />}
            />
            <Section tone="frame" labelledBy="join-title">
                <SectionHeading id="join-title" label="Add a server" title="One command, and it's in." align="center">
                    Run the line from Add server on the new machine. It connects out to your launchroom, joins the
                    private network and starts on the work you give it. Behind a home router it works the same: no
                    ports, no tunnel, no static IP.
                </SectionHeading>
                <JoinMoment className="mx-auto mt-12 max-w-[980px]" />
            </Section>
            <Section labelledBy="view-title">
                <SectionHeading id="view-title" label="Overview" title="Every server, on one screen.">
                    See each server with its location, its load over time and how the servers connect. Each server page
                    lists what runs there, with CPU and memory per service.
                </SectionHeading>
                <div className="mt-12">
                    <Screenshot id="server-topology" caption={false} sizes="(min-width: 1200px) 1180px, 100vw" />
                </div>
            </Section>
            <Section tone="frame" labelledBy="mesh-title">
                <FeatureRow
                    id="mesh-title"
                    label="Private network"
                    title="The database can live on another server."
                    visual={<Screenshot id="server-network" caption={false} sizes="(min-width: 1024px) 700px, 100vw" />}
                    points={[
                        'Apps reach a database by the same name, wherever it runs',
                        'Ports open on the private network only, and only for the servers that use them',
                        'Latency and bandwidth are measured between every pair of servers',
                    ]}
                    more={{ href: '/docs/how-it-works/mesh', text: 'How the private network works' }}
                >
                    Put the database on the server with the big disk and the app on the one close to your users. The
                    connection keeps working without any change to the app.
                </FeatureRow>
            </Section>
            <Section labelledBy="arch-heading">
                <SectionHeading
                    id="arch-heading"
                    label="How it fits together"
                    title="Start with one. Grow when you need to."
                />
                <div className="mt-10 max-w-[960px] rounded-sheet border border-border bg-frame p-4 sm:p-6">
                    <ArchitectureDiagram />
                </div>
            </Section>
            <Section tone="frame" labelledBy="facts-title">
                <h2 id="facts-title" className="sr-only">
                    Details
                </h2>
                <FactGrid
                    facts={[
                        { title: 'Roles', text: 'Each server builds, runs apps, serves traffic, or any mix of these.' },
                        {
                            title: 'Replicas',
                            text: 'Set the number of replicas; launchroom places them on your servers.',
                        },
                        { title: 'Self-healing', text: 'Replicas move when a server stops reporting for 90 seconds.' },
                        { title: 'More edges', text: 'Let a second server serve traffic. Certificates are shared.' },
                        {
                            title: 'Replace a server',
                            text: 'Move a dead server’s roles to a new one and restore its databases.',
                        },
                        {
                            title: 'Apps keep serving',
                            text: 'Servers keep their last instructions while the dashboard is offline.',
                        },
                    ]}
                />
            </Section>
            <FeatureFooter current="/features/servers" />
        </>
    );
}
