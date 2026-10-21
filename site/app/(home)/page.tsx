import { ArrowRight, BookOpen } from 'lucide-react';
import type { ReactNode } from 'react';
import { Screenshot } from '@/components/screenshot';
import { ButtonLink } from '@/components/marketing/button';
import { CopyCommand } from '@/components/marketing/copy-command';
import { DemoVideo } from '@/components/marketing/demo-video';
import { GitHubMark } from '@/components/marketing/github-mark';
import { ConnectMoment, JoinMoment } from '@/components/marketing/moments';
import { Container, MoreLink, Section, SectionHeading } from '@/components/marketing/section';
import {
    FreeVisual,
    InstallVisual,
    PullRequestPreview,
    ServerView,
    StaysYoursVisual,
} from '@/components/marketing/showcase';
import { githubUrl, installCommand, licenseUrl } from '@/lib/shared';

const included = [
    'Zero-downtime releases',
    'Instant rollback',
    'HTTPS on every domain',
    'Encrypted variables',
    'Backups to your S3',
    'Logs, metrics and alerts',
    'Teams and 2FA',
    'API and CLI',
];

const faq = [
    {
        q: 'Is launchroom free?',
        a: 'Yes. launchroom is open source under the MIT license. There is no paid edition, no account with us and no usage limit. You pay your server provider, nothing else.',
    },
    {
        q: 'Where does it run?',
        a: 'On your own server: any Linux machine with Docker, such as a VPS. The dashboard, your apps and your databases run there. Nothing is hosted by us.',
    },
    {
        q: 'Do I need more than one server?',
        a: 'No. One VPS runs the dashboard, the builds, your apps and their databases. Add a second machine when you want builds elsewhere or more capacity.',
    },
    {
        q: 'Can my computer at home join?',
        a: 'Yes. It connects out to your VPS and joins the private network by itself. You don’t open ports, set up a tunnel or need a static IP, and you can keep using the machine for everything else.',
    },
    {
        q: 'Do I need to know Docker?',
        a: 'No. launchroom detects Next.js, Laravel, Django, FastAPI, Node servers, static sites and more, and builds them without a Dockerfile. If your repository has one, launchroom uses it.',
    },
    {
        q: 'What happens to my apps if the dashboard goes down?',
        a: 'They keep running. Each server keeps the last instructions it received. You can restore the dashboard on any machine with one command and your recovery kit.',
    },
    {
        q: 'Is it secure?',
        a: 'The dashboard never logs into your servers. There is no SSH and no shell access: each server fetches signed instructions and checks them against its own rules. Secrets are encrypted, and every project environment has its own network.',
    },
];

export default function HomePage() {
    return (
        <>
            {/* Hero */}
            <section aria-labelledby="hero-title" className="pt-16 pb-16 sm:pt-24 sm:pb-24">
                <Container className="flex flex-col items-center text-center">
                    <a
                        href={githubUrl}
                        className="mb-8 inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1 text-[13px] text-muted-foreground shadow-card transition-colors duration-150 hover:border-border-strong hover:text-foreground"
                    >
                        <GitHubMark className="size-3.5" />
                        Free and open source · MIT
                        <ArrowRight className="size-3.5" aria-hidden="true" />
                    </a>
                    <h1 id="hero-title" className="type-hero text-balance text-foreground">
                        One place to deploy.
                    </h1>
                    <p className="mt-6 max-w-[620px] text-lg leading-8 text-pretty text-muted-foreground sm:text-xl sm:leading-8">
                        Everything a cloud host does for your apps, on your own server. Pick a repository, a domain and
                        a database, and launchroom wires up everything for you.{' '}
                        <span className="text-foreground">Free and open source.</span>
                    </p>
                    <CopyCommand command={installCommand} className="mt-10 w-full max-w-[600px]" />
                    <div className="mt-4 flex flex-wrap items-center justify-center gap-3">
                        <ButtonLink href="/docs/getting-started/install">Install guide</ButtonLink>
                        <ButtonLink href="/docs" variant="secondary">
                            <BookOpen className="size-4" aria-hidden="true" />
                            Read the docs
                        </ButtonLink>
                    </div>
                    <p className="mt-6 text-[13px] text-muted-foreground">
                        Runs on your server · Free, MIT licensed · No account needed
                    </p>
                </Container>

                <Container className="mt-16 sm:mt-20">
                    <div className="rounded-[20px] border border-border bg-frame p-1.5 shadow-float sm:p-2">
                        <Screenshot
                            id="canvas"
                            preload
                            caption={false}
                            sizes="(min-width: 1200px) 1180px, 150vw"
                            className="[&>div]:aspect-[16/10] [&>div]:rounded-[14px] sm:[&>div]:aspect-auto [&_img]:-mt-[17%] [&_img]:-ml-[38%] [&_img]:w-[150%] [&_img]:max-w-none sm:[&_img]:m-0 sm:[&_img]:w-full"
                        />
                    </div>
                </Container>
            </section>

            {/* 1 · What it is */}
            <Section tone="frame" labelledBy="what-title">
                <SectionHeading
                    id="what-title"
                    label="What launchroom is"
                    title="The comfort of a cloud host. On your server."
                    align="center"
                />
                <div className="mt-14 grid gap-4 md:grid-cols-3">
                    <WhatCard
                        title="Installs itself"
                        text="Run one command on any Linux server. launchroom sets up everything it needs, including HTTPS for its own address."
                        visual={<InstallVisual />}
                    />
                    <WhatCard
                        title="Your server stays yours"
                        text="launchroom keeps to one folder and leaves your other apps and files alone. You never touch Docker."
                        visual={<StaysYoursVisual />}
                    />
                    <WhatCard
                        title="Free and open source"
                        text="MIT licensed, with every feature included. There is no paid edition and no account with us."
                        visual={<FreeVisual />}
                    />
                </div>
            </Section>

            {/* 2 · Deploy */}
            <Section id="features" labelledBy="deploy-title">
                <SectionHeading
                    id="deploy-title"
                    label="Deploy"
                    title="Drop in a database. It’s connected."
                    align="center"
                >
                    Pick a repository and a domain, then drop a database onto the canvas. launchroom builds the app,
                    gets the certificate and hands the app its database credentials. Every push deploys.
                </SectionHeading>
                <ConnectMoment className="mx-auto mt-12 max-w-[980px]" />
                <div className="mt-8 flex justify-center">
                    <MoreLink href="/features/deployments">Deployments and previews</MoreLink>
                </div>
            </Section>

            {/* 3 · Servers */}
            <Section tone="frame" labelledBy="servers-title">
                <SectionHeading
                    id="servers-title"
                    label="Servers"
                    title="Your computer at home builds. Your VPS serves."
                    align="center"
                >
                    Run one line on a second machine and it joins. The two connect over a private network on their own,
                    with no ports to open. Tell launchroom what each machine does, and the app and its database can even
                    live on different servers.
                </SectionHeading>
                <JoinMoment className="mx-auto mt-12 max-w-[980px]" />
                <p className="mt-8 text-center text-[15px] text-muted-foreground">
                    One server is enough to start. More join the same way.{' '}
                    <MoreLink href="/features/servers">Servers</MoreLink>
                </p>
            </Section>

            {/* 4 · One screen */}
            <Section labelledBy="view-title">
                <SectionHeading id="view-title" label="Overview" title="Everything on one screen." align="center">
                    See each server with its load, and which service takes how much CPU and memory.
                </SectionHeading>
                <ServerView className="mx-auto mt-12 max-w-[1080px]" />
                <div className="mt-8 flex justify-center">
                    <MoreLink href="/features/observability">Logs and metrics</MoreLink>
                </div>
            </Section>

            {/* 5 · Previews */}
            <Section tone="frame" labelledBy="preview-title">
                <div className="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
                    <div className="flex flex-col gap-6">
                        <SectionHeading id="preview-title" label="Previews" title="A preview for every pull request.">
                            Open a pull request and launchroom deploys it with its own address and its own database,
                            then posts the link in the pull request. Merge, and it goes to production.
                        </SectionHeading>
                        <MoreLink href="/docs/projects/previews">Previews</MoreLink>
                    </div>
                    <PullRequestPreview />
                </div>
            </Section>

            <DemoVideo />

            {/* 6 · Own it */}
            <Section labelledBy="own-title">
                <div className="grid gap-12 lg:grid-cols-[minmax(0,6fr)_minmax(0,5fr)] lg:gap-16">
                    <div className="flex flex-col gap-8">
                        <SectionHeading
                            id="own-title"
                            label="Why launchroom"
                            title="Rent the server. Own the platform."
                        >
                            A VPS and launchroom do what a hosting platform does, for the price of the VPS. You pick the
                            provider and the region, and your data stays on your machines.
                        </SectionHeading>
                        <div className="flex flex-wrap gap-3">
                            <ButtonLink href={githubUrl}>
                                <GitHubMark className="size-4" />
                                View on GitHub
                            </ButtonLink>
                            <ButtonLink href={licenseUrl} variant="secondary">
                                MIT license
                            </ButtonLink>
                        </div>
                    </div>
                    <div className="flex flex-col gap-4">
                        <p className="type-label text-muted-foreground">Included, free</p>
                        <ul className="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
                            {included.map((i) => (
                                <li key={i} className="border-t border-border py-3 text-[15px]">
                                    {i}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </Section>

            {/* FAQ */}
            <Section tone="frame" labelledBy="faq-title">
                <div className="grid gap-12 lg:grid-cols-[minmax(0,4fr)_minmax(0,8fr)]">
                    <SectionHeading id="faq-title" label="FAQ" title="Questions and answers." />
                    <div className="flex flex-col">
                        {faq.map((f) => (
                            <details key={f.q} className="group border-b border-border py-5 first:pt-0">
                                <summary className="flex cursor-pointer list-none items-center justify-between gap-6 text-[16px] font-medium [&::-webkit-details-marker]:hidden">
                                    {f.q}
                                    <span
                                        aria-hidden="true"
                                        className="text-xl leading-none text-muted-foreground transition-transform duration-150 group-open:rotate-45"
                                    >
                                        +
                                    </span>
                                </summary>
                                <p className="mt-3 max-w-[65ch] text-[15px] leading-7 text-muted-foreground">{f.a}</p>
                            </details>
                        ))}
                    </div>
                </div>
            </Section>

            {/* Final CTA */}
            <section aria-labelledby="cta-title" className="py-24 sm:py-32">
                <Container className="flex flex-col items-center text-center">
                    <h2 id="cta-title" className="type-section max-w-[20ch] text-balance">
                        Your one place to deploy is one command away.
                    </h2>
                    <p className="mt-4 text-muted-foreground">Free, open source, on your own server.</p>
                    <CopyCommand command={installCommand} className="mt-10 w-full max-w-[560px]" />
                    <div className="mt-6 flex flex-wrap justify-center gap-3">
                        <ButtonLink href="/docs/getting-started/install" variant="secondary">
                            Install guide
                        </ButtonLink>
                        <ButtonLink href={githubUrl} variant="ghost">
                            <GitHubMark className="size-4" />
                            GitHub
                        </ButtonLink>
                    </div>
                </Container>
            </section>
        </>
    );
}

function WhatCard({ title, text, visual }: { title: string; text: string; visual: ReactNode }) {
    return (
        <div className="flex flex-col overflow-hidden rounded-card border border-border bg-card shadow-card">
            <div className="h-[280px] border-b border-border bg-recessed">{visual}</div>
            <div className="flex flex-col gap-2 p-6">
                <h3 className="type-heading">{title}</h3>
                <p className="text-[15px] leading-6 text-muted-foreground">{text}</p>
            </div>
        </div>
    );
}
