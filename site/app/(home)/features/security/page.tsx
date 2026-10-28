import type { Metadata } from 'next';
import { Screenshot } from '@/components/screenshot';
import { FactGrid, FeatureFooter, FeatureHero } from '@/components/marketing/feature-page';
import { ActionRules } from '@/components/marketing/insights';
import { FeatureRow, Section } from '@/components/marketing/section';
import { SignedStateCard } from '@/components/marketing/visuals';

export const metadata: Metadata = {
    title: 'Security',
    description:
        'A web firewall with the OWASP Core Rule Set on every public service, vulnerability checks that only alert when you need to act, container investigations and a package inventory. On your own server, with no central patch channel.',
    alternates: { canonical: '/features/security' },
};

export default function Page() {
    return (
        <>
            <FeatureHero
                label="Security"
                title="Protected from the first deploy."
                lead="Every public service sits behind a web firewall, and launchroom checks the packages of every running image against public vulnerability data. You hear about it when you need to act, with a look inside the container and the next steps."
                docsHref="/docs/security"
                visual={
                    <Screenshot
                        id="security-page"
                        preload
                        caption={false}
                        sizes="(min-width: 1200px) 1180px, 100vw"
                        frameless
                    />
                }
            />
            <Section tone="frame" labelledBy="firewall-title">
                <FeatureRow
                    id="firewall-title"
                    label="Web firewall"
                    title="A web firewall in front of every service."
                    wide
                    visual={
                        <Screenshot
                            id="security-firewall"
                            caption={false}
                            region={{ x: 24, y: 0, width: 1126, height: 948 }}
                            sizes="(min-width: 1200px) 1180px, 100vw"
                        />
                    }
                    points={[
                        'OWASP Core Rule Set 4.25 in the proxy, set to Block by default',
                        'Log only per service, with what it would have blocked and a one-click switch',
                        'An exception for a false positive from the blocked request',
                        'Events keep the client’s network, never the full address',
                    ]}
                    more={{ href: '/docs/security/web-firewall', text: 'Web firewall' }}
                >
                    SQL injection, cross-site scripting, code execution and scanners are stopped before they reach your
                    app. The Security page shows what was blocked, from which network and which services are targeted
                    most. The firewall filters common attacks. A vulnerable app still needs its fix.
                </FeatureRow>
            </Section>
            <Section labelledBy="checks-title">
                <FeatureRow
                    id="checks-title"
                    label="Vulnerability checks"
                    title="Alerts only when you need to act."
                    visual={<ActionRules />}
                    points={[
                        'Packages and runtimes of every live image, with their lockfiles',
                        'Matched against OSV, CISA’s list of exploited flaws and endoflife.date',
                        'Checked after every build and every 6 hours',
                    ]}
                    more={{ href: '/docs/security/alert-levels', text: 'Alert levels' }}
                >
                    A vulnerable package only raises an alert when its code can run in a public service and there is
                    something to do about it. Development dependencies, browser-only code and unreachable flaws are
                    listed under Worth knowing and stay quiet.
                </FeatureRow>
            </Section>
            <Section tone="frame" labelledBy="investigate-title">
                <FeatureRow
                    id="investigate-title"
                    label="Investigations"
                    title="Look inside the container after an alert."
                    reverse
                    visual={
                        <Screenshot
                            id="security-finding"
                            caption={false}
                            region={{ x: 462, y: 690, width: 764, height: 940 }}
                            sizes="(min-width: 1024px) 700px, 100vw"
                        />
                    }
                    points={[
                        'New or changed files and running processes of each replica',
                        'Firewall hits that match the kind of flaw, and the requests around them',
                        'Next steps: update the package, redeploy, restore a backup, change secrets',
                    ]}
                    more={{ href: '/docs/security/investigations', text: 'Investigations' }}
                >
                    When a finding needs action, launchroom checks the service right away, so the alert already says
                    what it found. A clean result doesn’t prove nothing happened, and the report says so.
                </FeatureRow>
            </Section>
            <Section labelledBy="inventory-title">
                <FeatureRow
                    id="inventory-title"
                    label="Inventory"
                    title="Every package, one search."
                    visual={
                        <Screenshot id="security-inventory" caption={false} sizes="(min-width: 1024px) 700px, 100vw" />
                    }
                    points={[
                        'Search by name and version across every project',
                        'Runtimes and engines with the end of their support',
                        'A newer patch release when one has been out for a month',
                    ]}
                    more={{ href: '/docs/security/inventory', text: 'Inventory' }}
                >
                    Find out which services run lodash 4.17.20 or Node.js 20 without opening a single repository.
                </FeatureRow>
            </Section>
            <Section tone="frame" labelledBy="alerts-title">
                <FeatureRow
                    id="alerts-title"
                    label="Alerts"
                    title="Two alert rules, on by default."
                    reverse
                    visual={
                        <Screenshot
                            id="alerts"
                            caption={false}
                            region={{ x: 574, y: 2432, width: 764, height: 200 }}
                            sizes="(min-width: 1024px) 700px, 100vw"
                        />
                    }
                    points={[
                        'A service needs a security update: findings marked Action needed',
                        'The web firewall stops working on a server for 5 minutes',
                        'Email, Slack, Discord and signed webhooks',
                    ]}
                    more={{ href: '/docs/observability/alerts', text: 'Alerts' }}
                >
                    Blocked attacks and Worth knowing entries never notify. The message names the service, the package
                    and the fix, and what the investigation found.
                </FeatureRow>
            </Section>
            <Section labelledBy="channel-title">
                <FeatureRow
                    id="channel-title"
                    label="Your instance decides"
                    title="No central patch channel."
                    wide
                    visual={
                        <FactGrid
                            facts={[
                                {
                                    title: 'Public data only',
                                    text: 'Each instance pulls OSV, CISA KEV and endoflife.date itself. OSV gets package names and versions, nothing about your projects.',
                                },
                                {
                                    title: 'Rules ship with releases',
                                    text: 'The firewall’s rule set is pinned in launchroom’s proxy image and changes only with a signed release.',
                                },
                                {
                                    title: 'Switch sources off',
                                    text: 'Turn each source off in the settings. Inventory and the firewall keep working without them.',
                                },
                            ]}
                        />
                    }
                >
                    launchroom runs no service that sends advisories, firewall rules or “patch now” commands to your
                    instance. Such a channel would be one place to reach every installation at once.
                </FeatureRow>
            </Section>
            <Section tone="frame" labelledBy="signed-title">
                <FeatureRow
                    id="signed-title"
                    label="The platform"
                    title="Nobody logs into your servers."
                    visual={<SignedStateCard />}
                    points={[
                        'Servers fetch signed instructions and check them against their own rules',
                        'No SSH and no remote shell from the dashboard',
                        'Docker is reached through a guard that allows only what launchroom needs',
                    ]}
                    more={{ href: '/docs/teams-and-security/security-model', text: 'Security model' }}
                >
                    Someone with full control of the dashboard can still only ask a server for what its rules allow.
                    Privileged containers and host mounts are refused on the server itself.
                </FeatureRow>
            </Section>
            <Section labelledBy="facts-title">
                <h2 id="facts-title" className="sr-only">
                    Details
                </h2>
                <FactGrid
                    facts={[
                        {
                            title: 'Encrypted secrets',
                            text: 'Encrypted at rest and never logged or returned by the API.',
                        },
                        {
                            title: 'Networks per environment',
                            text: 'Each project environment has its own network. Apps can’t reach launchroom’s own services.',
                        },
                        {
                            title: 'Audit log you can verify',
                            text: 'A hash chain with signed checkpoints, forwarded to syslog, a webhook or S3.',
                        },
                        {
                            title: 'Two-factor and passkeys',
                            text: 'Roles per team, and API tokens that belong to one team and expire.',
                        },
                        {
                            title: 'Protected previews',
                            text: 'Previews are behind a login or share link. Pull requests from forks never see secrets.',
                        },
                        {
                            title: 'Signed releases',
                            text: 'The installer and updates check release signatures before anything runs.',
                        },
                    ]}
                />
            </Section>
            <FeatureFooter current="/features/security" />
        </>
    );
}
