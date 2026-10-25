import { cn } from '@/lib/cn';

/** The launchroom mark ("Doorway", branding/README.md): a cloud with a door cut into its base, so it works on any background. */
export function LogoMark({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 100 100" aria-hidden="true" className={cn('shrink-0', className)}>
            <path
                fill="currentColor"
                d="M93.75 53.12C93.75 68.66 81.16 81.25 65.62 81.25H56.25V62.5C56.25 59.05 53.45 56.25 50 56.25C46.55 56.25 43.75 59.05 43.75 62.5V81.25H25C14.64 81.25 6.25 72.86 6.25 62.5C6.25 52.14 14.64 43.75 25 43.75C29.96 43.75 34.47 45.68 37.83 48.82C39.9 35.33 51.55 25 65.62 25C81.16 25 93.75 37.59 93.75 53.12Z"
            />
        </svg>
    );
}

export function Logo({ className }: { className?: string }) {
    return (
        <span className={cn('inline-flex items-center gap-2 text-foreground', className)}>
            <LogoMark className="size-6" />
            <span className="text-[15px] leading-none font-semibold tracking-[-0.01em]">launchroom</span>
        </span>
    );
}
