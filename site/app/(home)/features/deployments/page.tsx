import type { Metadata } from 'next';
import { Screenshot } from '@/components/screenshot';
import { FactGrid, FeatureFooter, FeatureHero } from '@/components/marketing/feature-page';
import { FeatureRow, Section } from '@/components/marketing/section';
import { DeployTimeline, PreviewCard, PromoteDiff } from '@/components/marketing/visuals';

export const metadata: Metadata = {
    title: 'Deployments and previews',
    description:
        'Push to deploy on your own server. launchroom builds without a Dockerfile, releases with zero downtime and gives every pull request a preview.',
    alternates: { canonical: '/features/deployments' },
};

export default function Page() {
    return (
        <>
            <FeatureHero
                label="Deployments and previews"
                title="Push, and it's live."
                lead="Every push to your main branch becomes a new version on your server. launchroom builds it, starts it next to the running version and switches traffic once the new version answers."
                docsHref="/docs/builds/how-builds-work"
                visual={
                    <Screenshot
                        id="deployment"
                        preload
                        caption={false}
                        sizes="(min-width: 1200px) 1180px, 100vw"
                        frameless
                    />
                }
            />
            <Section tone="frame" labelledBy="plan-title">
                <FeatureRow
                    id="plan-title"
                    label="Builds"
                    title="Builds from your repository as it is."
                    visual={<DeployTimeline />}
                    points={[
                        'Detects Next.js, Laravel, Django, FastAPI, Node and static sites',
                        'Shows the build plan before it builds; you can override any step',
                        'Uses your Dockerfile when the repository has one',
                    ]}
                    more={{ href: '/docs/builds/how-builds-work', text: 'How builds work' }}
                >
                    You don’t write a Dockerfile or a compose file. launchroom reads the repository, picks the build
                    steps and builds on your build server. The result is an image in the built-in registry.
                </FeatureRow>
            </Section>
            <Section labelledBy="release-title">
                <FeatureRow
                    id="release-title"
                    label="Releases"
                    title="When a release fails, the old one keeps serving."
                    reverse
                    visual={
                        <Screenshot
                            id="deployment-failed"
                            caption={false}
                            sizes="(min-width: 1024px) 700px, 100vw"
                            crop={{ aspect: '4/3', x: 50, y: 0 }}
                        />
                    }
                    points={[
                        'Health checks run from outside the container',
                        'Replicas update one at a time',
                        'The failing step opens with the error line highlighted',
                    ]}
                    more={{ href: '/docs/troubleshooting/builds', text: 'When a build fails' }}
                >
                    A deployment never changes after it is created. If a new version doesn’t start, traffic stays on the
                    current one, and the deployment page shows what went wrong.
                </FeatureRow>
            </Section>
            <Section tone="frame" labelledBy="preview-title">
                <FeatureRow
                    id="preview-title"
                    label="Previews"
                    title="A full copy of your app for every pull request."
                    visual={
                        <div className="flex flex-col gap-4">
                            <PreviewCard />
                            <Screenshot id="previews" caption={false} sizes="(min-width: 1024px) 700px, 100vw" />
                        </div>
                    }
                    points={[
                        'Its own URL and its own database',
                        'A status and a comment in the pull request',
                        'Behind a login or share link; pull requests from forks wait for approval',
                    ]}
                    more={{ href: '/docs/projects/previews', text: 'Previews' }}
                >
                    Open a pull request and launchroom deploys it next to production. Services you didn’t change reuse
                    their existing image.
                </FeatureRow>
            </Section>
            <Section labelledBy="promote-title">
                <FeatureRow
                    id="promote-title"
                    label="Environments"
                    title="Staging to production in one step."
                    reverse
                    visual={<PromoteDiff />}
                    more={{ href: '/docs/projects/environments', text: 'Environments and promotion' }}
                >
                    Compare two environments, select the changes to bring over and promote them. Domains and secrets
                    that belong to one environment stay where they are.
                </FeatureRow>
            </Section>
            <Section tone="frame" labelledBy="facts-title">
                <h2 id="facts-title" className="sr-only">
                    Details
                </h2>
                <FactGrid
                    facts={[
                        {
                            title: 'Staged changes',
                            text: 'Edits collect into one list. Review them, then deploy them together.',
                        },
                        {
                            title: 'Instant rollback',
                            text: 'Recent images are kept. Rolling back switches traffic to one of them.',
                        },
                        {
                            title: 'Config as code',
                            text: 'An optional launchroom.json in your repository, applied on push.',
                        },
                        { title: 'Git providers', text: 'GitHub, GitLab, Gitea, Forgejo and any git server over SSH.' },
                        {
                            title: 'Cron jobs',
                            text: 'Schedules with a time zone. They run even while the dashboard is offline.',
                        },
                        { title: 'Templates and Compose', text: 'Start from a template or import a Compose file.' },
                    ]}
                />
            </Section>
            <FeatureFooter current="/features/deployments" />
        </>
    );
}
