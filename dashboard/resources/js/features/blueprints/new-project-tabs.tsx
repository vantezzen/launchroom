import { PillTabs } from '@/components/patterns/pill-tabs';
import { compose, importMethod, templates } from '@/routes/projects';

/**
 * Back to the New project start screen from the template gallery and the
 * Compose import (docs/17 §2.1), as one segmented row under the page title.
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
                    label: 'New project',
                    href: importMethod().url,
                    active: current === 'repository',
                },
                {
                    label: 'Templates',
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
