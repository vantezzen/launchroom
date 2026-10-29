import { ExternalLink } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useDocsUrl } from '@/features/settings/settings-card';

/**
 * "How it works" (docs/22 §9.7): the page's only explanation, two or
 * three sentences per part.
 */
export function HowItWorks({
    open,
    onOpenChange,
    ruleSet,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    ruleSet: string | null;
}) {
    const docs = useDocsUrl();
    const sections = [
        {
            title: 'Vulnerability checks',
            body: 'launchroom reads the packages and runtimes of every live image when it is deployed. Every 6 hours it asks OSV which published advisories affect those versions, and checks CISA’s list of known exploited vulnerabilities and endoflife.date once a day. OSV receives package names and versions only, never project, service or user names.',
            link: ['security/vulnerability-checks', 'Vulnerability checks'],
        },
        {
            title: 'Action needed and Worth knowing',
            body: 'A finding needs action when the vulnerable code can run while your service handles requests, the advisory is high or critical with a fix, and the service is public, or when it is known to be exploited. Everything else is listed under Worth knowing and never sends a notification.',
            link: ['security/alert-levels', 'Alert levels'],
        },
        {
            title: 'Web firewall',
            body: `${ruleSet ?? 'The OWASP Core Rule Set'} checks requests at the edge before they reach your services. Every service blocks attacks by default and can switch to Log only or Off. A blocked request shows a reference you can turn into an exception. It checks the first 32 KiB of each request body (more if you choose) and the first 256 fields; anything after that reaches your app unchecked, so treat it as a filter against common attacks, not a replacement for fixing a vulnerable app.`,
            link: ['security/web-firewall', 'Web firewall'],
        },
        {
            title: 'Investigations',
            body: 'An investigation reads what changed in the running containers and which processes run, then adds the firewall’s results, the requests and what the service can reach. A clean result can’t prove nothing happened, because someone who got in can remove their traces.',
            link: ['security/investigations', 'Investigations'],
        },
        {
            title: 'Your data',
            body: 'For requests the firewall blocks, launchroom keeps the client’s network (a /24 or /48), never the full address, for 7 days. Nothing else is stored per request.',
            link: ['security/privacy-and-data', 'Privacy and data'],
        },
    ];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                <DialogTitle>How security works</DialogTitle>
                <DialogDescription className="sr-only">
                    What the Security page checks and where its data comes from.
                </DialogDescription>
                <div className="flex flex-col gap-5">
                    {sections.map((section) => (
                        <section
                            key={section.title}
                            className="flex flex-col gap-1.5"
                        >
                            <h3 className="type-label">{section.title}</h3>
                            <p className="type-small text-muted-foreground">
                                {section.body}
                            </p>
                            <a
                                href={docs(section.link[0])}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex w-fit items-center gap-1 type-small text-signal hover:underline"
                            >
                                {section.link[1]}
                                <ExternalLink
                                    aria-hidden="true"
                                    className="size-3"
                                />
                            </a>
                        </section>
                    ))}
                    <p className="border-t pt-4 type-small text-faint">
                        Advisory data from OSV (CC-BY 4.0) and its sources, CISA
                        KEV (public domain) and endoflife.date (MIT).
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    );
}
