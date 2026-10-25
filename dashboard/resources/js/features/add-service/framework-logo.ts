/**
 * Logo key (components/logo.tsx) for a detected framework or, failing
 * that, the runtime a repository folder's manifest suggests.
 */
const frameworks: [RegExp, string][] = [
    [/next/i, 'nextjs'],
    [/laravel/i, 'laravel'],
    [/django/i, 'django'],
    [/fastapi/i, 'fastapi'],
    [/flask/i, 'flask'],
    [/astro/i, 'astro'],
    [/nuxt/i, 'nuxt'],
    [/remix/i, 'remix'],
    [/svelte/i, 'svelte'],
    [/rails/i, 'rails'],
    [/vite|react|vue/i, 'vite'],
];

const runtimes: Record<string, string> = {
    node: 'node',
    php: 'php',
    python: 'python',
    go: 'go',
    ruby: 'ruby',
    rust: 'rust',
    deno: 'deno',
    docker: 'docker',
};

export function frameworkLogo(
    framework: string | null | undefined,
    runtime?: string | null,
): string {
    if (framework) {
        for (const [pattern, key] of frameworks) {
            if (pattern.test(framework)) {
                return key;
            }
        }
    }

    return (runtime && runtimes[runtime.toLowerCase()]) || 'app';
}

/** ["Next.js 16.1", "pnpm", "Node 22"] from a detected plan, for a `MetaLine`. */
export function planParts(plan: App.Data.Canvas.DetectedPlanData): string[] {
    return [
        [plan.framework, plan.frameworkVersion].filter(Boolean).join(' '),
        plan.packageManager,
        plan.runtime,
    ].filter((part): part is string => Boolean(part));
}
