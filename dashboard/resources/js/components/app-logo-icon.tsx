import type { SVGAttributes } from 'react';

/**
 * The launchroom mark ("Doorway", chosen 2026-10-25; see branding/README.md):
 * a cloud with a round-topped door cut into its flat base. It is drawn in the
 * current colour with the door transparent, so it works on any background in
 * light and dark mode. The cloud is wider than tall: it fills the width of
 * the square box and sits slightly low, as in the source asset, so size the
 * box a step larger than a square glyph would need.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 100 100"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >
            <path
                fill="currentColor"
                d="M93.75 53.12C93.75 68.66 81.16 81.25 65.62 81.25H56.25V62.5C56.25 59.05 53.45 56.25 50 56.25C46.55 56.25 43.75 59.05 43.75 62.5V81.25H25C14.64 81.25 6.25 72.86 6.25 62.5C6.25 52.14 14.64 43.75 25 43.75C29.96 43.75 34.47 45.68 37.83 48.82C39.9 35.33 51.55 25 65.62 25C81.16 25 93.75 37.59 93.75 53.12Z"
            />
        </svg>
    );
}
