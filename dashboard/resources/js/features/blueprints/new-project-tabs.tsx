import { PillTabs } from '@/components/patterns/pill-tabs';
import { compose, importMethod, templates } from '@/routes/projects';

/**
 * The three ways to start a project (docs/17 §2.1): a repository, a
 * template or a Compose file, as one segmented row under the page title.
 */
export function NewProjectTabs({
    current,
}: {
    current: 'repository' | 'template' | 'compose';
}) {
    return (
        <PillTabs
            label="Ways to start"
            tabs={[
                {
                    label: 'Repository',
                    href: importMethod().url,
                    active: current === 'repository',
                },
                {
                    label: 'Template',
                    href: templates().url,
                    active: current === 'template',
                },
                {
                    label: 'Compose file',
                    href: compose().url,
                    active: current === 'compose',
                },
            ]}
        />
    );
}
