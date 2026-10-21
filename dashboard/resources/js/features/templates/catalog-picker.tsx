import { useEffect, useState } from 'react';
import { Logo } from '@/components/logo';
import { SheetSection } from '@/components/patterns/sheet';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { requestJson } from '@/features/projects/http';
import { catalog } from '@/routes/projects';
import { store } from '@/routes/projects/catalog';
import { TemplateGallery } from './template-gallery';
import { TemplateSetup } from './template-setup';

type Template = App.Data.Templates.TemplateSummaryData;

/**
 * "Add from catalog" inside the canvas' add sheet (docs/17 §4): the same
 * grid as the gallery, then the template's detail and inputs; its services
 * are added to this environment, staged for the next deploy.
 */
export function CatalogPicker({
    project,
    environment,
    onDone,
}: {
    project: string;
    environment: string;
    onDone: () => void;
}) {
    const [data, setData] = useState<{
        templates: Template[];
        categories: Record<string, string>;
    } | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [picked, setPicked] = useState<Template | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        requestJson<{
            templates: Template[];
            categories: Record<string, string>;
        }>(
            'get',
            catalog([project, environment]).url,
            undefined,
            controller.signal,
        )
            .then(setData)
            .catch(
                (cause: unknown) =>
                    !controller.signal.aborted &&
                    setFailed(
                        cause instanceof Error
                            ? cause.message
                            : 'The catalog didn’t load.',
                    ),
            );

        return () => controller.abort();
    }, [project, environment]);

    if (picked) {
        return (
            <div className="-mx-5 -my-5 flex flex-col [&>section+section]:border-t">
                <SheetSection>
                    <div className="flex items-center gap-3">
                        <Logo
                            kind={picked.logo}
                            name={picked.name}
                            size={44}
                            decorative
                        />
                        <span className="flex min-w-0 flex-col">
                            <span className="type-heading">{picked.name}</span>
                            <span className="truncate type-small text-muted-foreground">
                                {picked.tagline}
                            </span>
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="ml-auto"
                            onClick={() => setPicked(null)}
                        >
                            All apps
                        </Button>
                    </div>
                </SheetSection>
                <TemplateSetup
                    key={picked.id}
                    template={picked}
                    url={store([project, environment, picked.id]).url}
                    askName={false}
                    submitLabel="Add to canvas"
                    onDone={onDone}
                />
            </div>
        );
    }

    if (failed) {
        return <p className="type-body text-status-error">{failed}</p>;
    }

    if (!data) {
        return (
            <p className="flex items-center gap-2 type-small text-muted-foreground">
                <Spinner /> Loading the catalog…
            </p>
        );
    }

    return (
        <TemplateGallery
            templates={data.templates}
            categories={data.categories}
            onOpen={setPicked}
            compact
        />
    );
}
