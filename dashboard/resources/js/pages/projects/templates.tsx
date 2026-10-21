import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { Logo } from '@/components/logo';
import { Page, PageHeader } from '@/components/patterns/page';
import { SidePanel } from '@/components/patterns/sheet';
import { NewProjectTabs } from '@/features/blueprints/new-project-tabs';
import { TemplateGallery } from '@/features/templates/template-gallery';
import { TemplateSetup } from '@/features/templates/template-setup';
import {
    index as projectsIndex,
    templates as templatesRoute,
} from '@/routes/projects';
import { store } from '@/routes/projects/templates';

type Template = App.Data.Templates.TemplateSummaryData;

/**
 * "Start from a template" (docs/17 §4): the catalog as an app grid; a
 * template opens a sheet with what you get, what it needs and the few
 * inputs that matter, then deploys directly.
 */
export default function Templates({
    templates,
    categories,
}: {
    templates: Template[];
    categories: Record<string, string>;
}) {
    const [open, setOpen] = useState<Template | null>(null);
    const [shown, setShown] = useState<Template | null>(null);

    return (
        <>
            <Head title="Start from a template" />
            <Page size="wide">
                <PageHeader
                    title="Start from a template"
                    description="Apps and stacks that are ready to run. Secrets are generated, the first deploy starts right away."
                />
                <NewProjectTabs current="template" />
                <TemplateGallery
                    templates={templates}
                    categories={categories}
                    onOpen={(template) => {
                        setShown(template);
                        setOpen(template);
                    }}
                />
            </Page>
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
                        url={store(shown.id).url}
                        askName
                        submitLabel="Deploy"
                    />
                )}
            </SidePanel>
        </>
    );
}

Templates.layout = {
    breadcrumbs: [
        { title: 'Projects', href: projectsIndex() },
        { title: 'Start from a template', href: templatesRoute() },
    ],
};
