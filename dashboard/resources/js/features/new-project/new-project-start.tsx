import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import { Logo } from '@/components/logo';
import { Page } from '@/components/patterns/page';
import { SidePanel } from '@/components/patterns/sheet';
import { StartEmptyDialog } from '@/features/projects/start-empty-dialog';
import { TemplateSetup } from '@/features/templates/template-setup';
import { compose, templates as templatesRoute } from '@/routes/projects';
import { store as templateStore } from '@/routes/projects/templates';
import { RepositoryList } from './repository-list';
import { RepositoryUrlField } from './repository-url-field';
import { BlankCanvasCard, ComposeCard, TemplateTile } from './start-cards';

type Repository = App.Data.Git.GitRepositoryData;
type Template = App.Data.Templates.TemplateSummaryData;

/** Templates shown on the start screen, in this order when they exist. */
const FEATURED = [
    'umami',
    'nextjs-postgres',
    'n8n',
    'plausible',
    'ghost',
    'supabase',
];

export type NewProjectStartProps = {
    providers: string[];
    installUrl: string | null;
    githubConnected: boolean;
    canConnectGit: boolean;
    templates: Template[];
    categories: Record<string, string>;
    onPick: (repository: Repository, branch?: string | null) => void;
};

/**
 * New project's start screen (docs/08 §13): one field for any public Git
 * URL, the team's repositories beside a shelf of templates, and the other
 * two ways to start (a blank canvas, a Compose file).
 */
export function NewProjectStart({
    providers,
    installUrl,
    githubConnected,
    canConnectGit,
    templates,
    categories,
    onPick,
}: NewProjectStartProps) {
    const [startEmpty, setStartEmpty] = useState(false);
    const [open, setOpen] = useState<Template | null>(null);
    const [shown, setShown] = useState<Template | null>(null);
    const openTemplate = (template: Template) => {
        setShown(template);
        setOpen(template);
    };
    const featured = [
        ...FEATURED.flatMap((id) => templates.filter((t) => t.id === id)),
        ...templates.filter((t) => !FEATURED.includes(t.id)),
    ].slice(0, 6);

    return (
        <>
            <Page size="wide" className="gap-12">
                <section
                    aria-labelledby="new-project-title"
                    className="mx-auto flex w-full max-w-[760px] flex-col gap-6 pt-2 sm:pt-6"
                >
                    <h1
                        id="new-project-title"
                        className="text-center type-display sm:text-[36px] sm:leading-[42px]"
                    >
                        Deploy something new
                    </h1>
                    <RepositoryUrlField
                        templates={templates}
                        onContinue={(repository, branch) =>
                            onPick(repository, branch)
                        }
                        onUseTemplate={openTemplate}
                    />
                    <div className="grid gap-3 pt-2 sm:grid-cols-2">
                        <BlankCanvasCard onStart={() => setStartEmpty(true)} />
                        <ComposeCard href={compose().url} />
                    </div>
                </section>

                <div className="grid gap-x-6 gap-y-12 lg:grid-cols-2">
                    <section
                        aria-labelledby="repositories-title"
                        className="flex min-w-0 flex-col gap-3.5"
                    >
                        <div className="flex h-8 items-center">
                            <h2
                                id="repositories-title"
                                className="type-heading"
                            >
                                Your repositories
                            </h2>
                        </div>
                        <RepositoryList
                            providers={providers}
                            installUrl={installUrl}
                            canConnectGit={canConnectGit}
                            githubConnected={githubConnected}
                            onPick={(repository) => onPick(repository)}
                        />
                    </section>

                    <section
                        aria-labelledby="templates-title"
                        className="flex min-w-0 flex-col gap-3.5"
                    >
                        <div className="flex h-8 items-center justify-between gap-4">
                            <h2 id="templates-title" className="type-heading">
                                Start from a template
                            </h2>
                            <Link
                                href={templatesRoute()}
                                className="inline-flex items-center gap-1 type-label text-muted-foreground hover:text-foreground"
                            >
                                All {templates.length} templates
                                <ArrowRight
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            </Link>
                        </div>
                        <ul className="grid grid-cols-2 gap-3">
                            {featured.map((template) => (
                                <li key={template.id}>
                                    <TemplateTile
                                        template={template}
                                        category={categories[template.category]}
                                        onOpen={openTemplate}
                                    />
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>
            </Page>

            <StartEmptyDialog open={startEmpty} onOpenChange={setStartEmpty} />
            <SidePanel
                open={open !== null}
                onOpenChange={(next) => !next && setOpen(null)}
                size="lg"
                title={shown?.name ?? ''}
                description={shown?.tagline}
                media={
                    shown ? (
                        <Logo
                            kind={shown.logo}
                            name={shown.name}
                            size={44}
                            decorative
                        />
                    ) : undefined
                }
            >
                {shown && (
                    <TemplateSetup
                        key={shown.id}
                        template={shown}
                        url={templateStore(shown.id).url}
                        askName
                        submitLabel="Create and deploy"
                    />
                )}
            </SidePanel>
        </>
    );
}
