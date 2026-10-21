import type { Metadata } from 'next';
import { Screenshot } from '@/components/screenshot';
import { FactGrid, FeatureFooter, FeatureHero } from '@/components/marketing/feature-page';
import { FeatureRow, Section } from '@/components/marketing/section';
import { SignedStateCard } from '@/components/marketing/visuals';

export const metadata: Metadata = {
    title: 'Security',
    description:
        'No SSH and no shell access. Servers run signed instructions under their own rules. Encrypted secrets, client certificates and an audit log you can verify.',
    alternates: { canonical: '/features/security' },
};

export default function Page() {
    return (
        <>
            <FeatureHero
                label="Security"
                title="Your servers only take signed instructions."
                lead="The dashboard never logs into your servers. Each server fetches a signed description of what it should run, checks it against its own rules and applies it. There is no remote shell."
                docsHref="/docs/teams-and-security/security-model"
                visual={
                    <Screenshot
                        id="mtls"
                        preload
                        caption={false}
                        sizes="(min-width: 1200px) 1180px, 100vw"
                        className="[&>div]:rounded-[14px]"
                    />
                }
            />
            <Section tone="frame" labelledBy="signed-title">
                <FeatureRow
                    id="signed-title"
                    label="Servers"
                    title="Nobody logs into your servers."
                    visual={<SignedStateCard />}
                    points={[
                        'The dashboard signs every response, and each server signs its requests with its own key',
                        'Each server refuses privileged containers, host mounts and access to Docker itself',
                        'Docker is reached through a guard that allows only what launchroom needs',
                    ]}
                    more={{ href: '/docs/how-it-works/launchroomd', text: 'What launchroomd does' }}
                >
                    Someone with full control of the dashboard can still only ask a server for what its rules allow.
                    Joining the private network gives no access by itself.
                </FeatureRow>
            </Section>
            <Section labelledBy="audit-title">
                <FeatureRow
                    id="audit-title"
                    label="Audit log"
                    title="An audit log you can verify."
                    reverse
                    visual={<Screenshot id="activity" caption={false} sizes="(min-width: 1024px) 700px, 100vw" />}
                    points={[
                        'A hash chain with signed checkpoints every ten minutes',
                        'The verifier names every edited, deleted or reordered entry',
                        'Forward to syslog, a signed webhook or append-only S3',
                    ]}
                    more={{ href: '/docs/teams-and-security/audit-log', text: 'Audit log' }}
                >
                    Every deploy, change, console session and sign-in is recorded. launchroom shows when the log was
                    last verified, and you can check it yourself.
                </FeatureRow>
            </Section>
            <Section tone="frame" labelledBy="account-title">
                <FeatureRow
                    id="account-title"
                    label="Accounts"
                    title="Two-factor, passkeys and scoped tokens."
                    visual={<Screenshot id="two-factor" caption={false} sizes="(min-width: 1024px) 700px, 100vw" />}
                    more={{ href: '/docs/teams-and-security/two-factor', text: 'Two-factor authentication' }}
                >
                    Invite your team with roles. Sign in with an authenticator app or a passkey. API tokens belong to
                    one team, carry explicit permissions and expire.
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
                            title: 'Client certificates',
                            text: 'Optional mTLS for every server connection, renewed every week.',
                        },
                        {
                            title: 'Protected previews',
                            text: 'Previews are behind a login or share link. Pull requests from forks never see secrets.',
                        },
                        {
                            title: 'Signed releases',
                            text: 'The installer and updates check release signatures before anything runs.',
                        },
                        {
                            title: 'Open source',
                            text: 'The code and the security model are in the public repository.',
                        },
                    ]}
                />
            </Section>
            <FeatureFooter current="/features/security" />
        </>
    );
}
