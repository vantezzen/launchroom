import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Logo } from '@/components/logo';
import { Input } from '@/components/ui/input';
import { resourcesLine } from '@/features/blueprints/blueprint-canvas';
import { plural } from '@/features/canvas/format';
import { cn } from '@/lib/utils';

type Template = App.Data.Templates.TemplateSummaryData;

/**
 * The catalog as an app grid (docs/17 §4): search, category pills and a
 * card per template (logo, name, one line, what it runs). `compact` is the
 * two-column version inside the canvas' add sheet.
 */
export function TemplateGallery({
    templates,
    categories,
    onOpen,
    compact = false,
}: {
    templates: Template[];
    categories: Record<string, string>;
    onOpen: (template: Template) => void;
    compact?: boolean;
}) {
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState<string | null>(null);
    const used = Object.entries(categories).filter(([id]) =>
        templates.some((template) => template.category === id),
    );
    const shown = useMemo(() => {
        const query = search.trim().toLowerCase();

        return templates.filter(
            (template) =>
                (category === null || template.category === category) &&
                (query === '' ||
                    `${template.name} ${template.tagline} ${categories[template.category] ?? ''}`
                        .toLowerCase()
                        .includes(query)),
        );
    }, [templates, categories, category, search]);

    return (
        <div className="flex flex-col gap-5">
            <div
                className={cn(
                    'flex flex-col gap-3',
                    !compact && 'sm:flex-row sm:items-center',
                )}
            >
                <div className={cn('relative', compact ? 'w-full' : 'sm:w-72')}>
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search apps and stacks"
                        aria-label="Search templates"
                        className="pl-9"
                    />
                </div>
                <div
                    role="radiogroup"
                    aria-label="Category"
                    className="-mx-1 flex flex-wrap gap-1.5 px-1"
                >
                    {[[null, 'All'] as const, ...used].map(([id, label]) => (
                        <button
                            key={id ?? 'all'}
                            type="button"
                            role="radio"
                            aria-checked={category === id}
                            onClick={() => setCategory(id)}
                            className={cn(
                                'h-8 rounded-full border px-3 type-small transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                category === id
                                    ? 'border-foreground bg-foreground text-background'
                                    : 'bg-card text-muted-foreground hover:border-border-strong hover:text-foreground',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            {shown.length === 0 ? (
                <p className="rounded-xl border border-dashed p-8 text-center type-body text-muted-foreground">
                    Nothing matches “{search}”.
                </p>
            ) : (
                <ul
                    className={cn(
                        'grid gap-4',
                        compact
                            ? 'grid-cols-1 sm:grid-cols-2'
                            : 'sm:grid-cols-2 lg:grid-cols-3',
                    )}
                >
                    {shown.map((template) => (
                        <li key={template.id}>
                            <TemplateCard
                                template={template}
                                category={categories[template.category]}
                                onOpen={onOpen}
                                compact={compact}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function TemplateCard({
    template,
    category,
    onOpen,
    compact,
}: {
    template: Template;
    category?: string;
    onOpen: (template: Template) => void;
    compact: boolean;
}) {
    const logos = compact
        ? []
        : [
              ...new Set(
                  template.preview.services.map((service) => service.logo),
              ),
          ].filter((logo) => logo !== template.logo);

    return (
        <button
            type="button"
            onClick={() => onOpen(template)}
            className={cn(
                'group flex h-full w-full flex-col gap-4 rounded-[14px] border bg-card text-left shadow-card transition-[border-color,box-shadow] duration-fast hover:border-border-strong focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none',
                compact ? 'p-4' : 'p-5',
            )}
        >
            <span className="flex items-start gap-3">
                <Logo
                    kind={template.logo}
                    name={template.name}
                    size={44}
                    decorative
                />
                <span className="flex min-w-0 flex-col gap-0.5 pt-0.5">
                    <span className="type-heading">{template.name}</span>
                    {category && (
                        <span className="type-small text-muted-foreground">
                            {category}
                        </span>
                    )}
                </span>
            </span>
            <span className="line-clamp-2 type-body text-muted-foreground">
                {template.tagline}
            </span>
            <span className="mt-auto flex items-center gap-2 border-t pt-3 type-small text-muted-foreground">
                <span className="flex -space-x-1.5" aria-hidden="true">
                    {logos.slice(0, 3).map((logo) => (
                        <Logo
                            key={logo}
                            kind={logo}
                            size={20}
                            decorative
                            className="ring-2 ring-card"
                        />
                    ))}
                </span>
                <span className="truncate">
                    {plural(template.preview.services.length, 'service')} ·{' '}
                    {resourcesLine(template.preview)}
                </span>
            </span>
        </button>
    );
}
