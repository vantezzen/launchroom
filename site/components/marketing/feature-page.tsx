import Link from 'next/link';
import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { ButtonLink } from './button';
import { CopyCommand } from './copy-command';
import { Container, Section, SectionHeading } from './section';
import { featurePages } from '@/lib/features';
import { installCommand } from '@/lib/shared';

/** Shared frame for /features/* pages: hero, sections, facts, next pages, install. */
export function FeatureHero({
    label,
    title,
    lead,
    docsHref,
    visual,
    framed = true,
}: {
    label: string;
    title: string;
    lead: ReactNode;
    docsHref: string;
    visual: ReactNode;
    /** Screenshots get a frame and a height cap; rebuilt components bring their own cards. */
    framed?: boolean;
}) {
    return (
        <section aria-labelledby="feature-title" className="pt-16 pb-16 sm:pt-24">
            <Container>
                <div className="flex max-w-[760px] flex-col gap-5">
                    <p className="type-label text-muted-foreground">{label}</p>
                    <h1 id="feature-title" className="type-hero text-[clamp(2.5rem,6vw,4rem)] text-balance">
                        {title}
                    </h1>
                    <p className="max-w-[640px] text-lg leading-8 text-pretty text-muted-foreground">{lead}</p>
                    <div className="flex flex-wrap gap-3 pt-2">
                        <ButtonLink href={docsHref}>Read the docs</ButtonLink>
                        <ButtonLink href="/docs/getting-started/install" variant="secondary">
                            Install launchroom
                        </ButtonLink>
                    </div>
                </div>
                {framed ? (
                    <div className="mt-14 rounded-[20px] border border-border bg-frame p-1.5 shadow-float sm:p-2">
                        <div className="max-h-[min(80vh,760px)] overflow-hidden rounded-[14px]">{visual}</div>
                    </div>
                ) : (
                    <div className="mt-14">{visual}</div>
                )}
            </Container>
        </section>
    );
}

export function FactGrid({ facts }: { facts: Array<{ title: string; text: string }> }) {
    return (
        <dl className="grid gap-x-10 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
            {facts.map((f) => (
                <div key={f.title} className="flex flex-col gap-1.5 border-t border-border pt-5">
                    <dt className="type-heading">{f.title}</dt>
                    <dd className="text-[15px] leading-6 text-muted-foreground">{f.text}</dd>
                </div>
            ))}
        </dl>
    );
}

export function FeatureFooter({ current }: { current: string }) {
    const others = featurePages.filter((f) => f.href !== current);
    return (
        <>
            <Section tone="frame" labelledBy="more-title">
                <SectionHeading id="more-title" title="More of launchroom" />
                <ul className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {others.map((f) => (
                        <li key={f.href}>
                            <Link
                                href={f.href}
                                className="group flex h-full flex-col gap-2 rounded-card border border-border bg-card p-5 shadow-card transition-colors duration-150 hover:border-border-strong"
                            >
                                <span className="type-heading">{f.title}</span>
                                <span className="text-sm text-muted-foreground">{f.summary}</span>
                                <ArrowRight
                                    className="mt-auto size-4 text-muted-foreground transition-transform duration-150 group-hover:translate-x-0.5"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    ))}
                </ul>
            </Section>
            <section aria-labelledby="install-title" className="py-20 sm:py-28">
                <Container className="flex flex-col items-center text-center">
                    <h2 id="install-title" className="type-section text-balance">
                        Install it on your server with one command.
                    </h2>
                    <CopyCommand command={installCommand} className="mt-8 w-full max-w-[560px]" />
                </Container>
            </section>
        </>
    );
}
