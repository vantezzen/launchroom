import type { CSSProperties } from 'react';
import { hasLogo, Logo, logoLabel } from '@/components/logo';

/** Labels for the server's glyph keys (App\Support\Canvas\ServiceGlyph) that Logo doesn't name. */
const labels: Record<string, string> = {
    docker: 'Image',
    github: 'GitHub repository',
    gitlab: 'GitLab repository',
    gitea: 'Gitea repository',
    git: 'Git repository',
    static: 'Static site',
    worker: 'Worker',
    cron: 'Cron',
    app: 'App',
};

export function glyphLabel(logo: string): string {
    return labels[logo] ?? (hasLogo(logo) ? logoLabel(logo) : 'App');
}

/**
 * The service's logo tile (monochrome, components/logo.tsx). Kept as the
 * canvas' entry point so every place that shows a service looks the same.
 */
export function ServiceGlyph({
    logo,
    className,
    size = 'md',
    style,
}: {
    logo: string;
    className?: string;
    size?: 'sm' | 'md' | 'lg';
    style?: CSSProperties;
}) {
    return (
        <Logo
            kind={logo}
            name={glyphLabel(logo)}
            size={size === 'lg' ? 44 : size === 'md' ? 32 : 20}
            className={className}
            style={style}
        />
    );
}
