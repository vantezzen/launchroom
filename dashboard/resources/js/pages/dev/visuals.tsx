import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import VisualPrimitives, {
    VISUAL_SECTIONS,
} from '@/components/charts/visuals.design';

/**
 * Local-only page for the visual primitives (also on /dev/design). `?theme=
 * dark|light` forces a theme without touching the saved preference, for
 * screenshots.
 */
export default function DesignVisuals() {
    useEffect(() => {
        const theme = new URLSearchParams(window.location.search).get('theme');

        if (theme === 'dark' || theme === 'light') {
            document.documentElement.classList.toggle('dark', theme === 'dark');
            document.documentElement.style.colorScheme = theme;
        }
    }, []);

    return (
        <>
            <Head title="Visual primitives" />
            <div className="mx-auto flex w-full max-w-[1280px] flex-col gap-10 px-4 pt-8 pb-16 sm:px-8">
                <header className="flex flex-col gap-2">
                    <h1 className="type-title">Visual primitives</h1>
                    <p className="type-body text-muted-foreground">
                        Charts, steps, logs, device art, map and cards with
                        sample data. Local only.
                    </p>
                    <nav
                        aria-label="Sections"
                        className="flex flex-wrap gap-1.5 pt-2"
                    >
                        {VISUAL_SECTIONS.map((id) => (
                            <a
                                key={id}
                                href={`#${id}`}
                                className="rounded-full border px-2.5 py-0.5 type-small text-muted-foreground capitalize hover:text-foreground"
                            >
                                {id}
                            </a>
                        ))}
                    </nav>
                </header>
                <VisualPrimitives />
            </div>
        </>
    );
}
