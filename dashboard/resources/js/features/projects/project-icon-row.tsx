import { router } from '@inertiajs/react';
import { MoreHorizontal, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { ProjectIcon } from '@/components/project-icon';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SettingsRow } from '@/features/settings/settings-card';
import { destroy, store } from '@/routes/projects/icon';

function describe(icon: App.Data.Projects.ProjectIconData): string {
    if (icon.mode === 'uploaded') {
        return 'Your upload. Shown on cards, in the switcher and in search.';
    }

    if (icon.mode === 'none') {
        return 'No icon: the project shows its initials.';
    }

    return icon.url && icon.source
        ? `The favicon of ${icon.source}, refreshed after deploys.`
        : 'We use your app’s favicon once production has a domain and is live.';
}

/**
 * Settings → General → Icon (docs/08 §13): the current icon, Upload (PNG,
 * ICO, SVG or WebP up to 256 KB; checked and re-encoded on the server) and
 * back to automatic or no icon. Each choice applies right away.
 */
export function ProjectIconRow({
    project,
    icon,
    canManage,
}: {
    project: App.Data.Canvas.ProjectData;
    icon: App.Data.Projects.ProjectIconData;
    canManage: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const upload = (file: File) => {
        setError(null);
        setBusy(true);
        router.post(
            store(project.id).url,
            { icon: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (errors) =>
                    setError(errors.icon ?? 'The image could not be used.'),
                onFinish: () => {
                    setBusy(false);

                    if (input.current) {
                        input.current.value = '';
                    }
                },
            },
        );
    };

    const reset = (mode: 'automatic' | 'none') => {
        setError(null);
        router.delete(destroy(project.id).url, {
            data: { mode },
            preserveScroll: true,
        });
    };

    return (
        <SettingsRow
            label="Icon"
            description={describe(icon)}
            media={
                <ProjectIcon name={project.name} iconUrl={icon.url} size={44} />
            }
        >
            <div className="flex flex-col items-end gap-1 max-sm:items-start">
                {canManage && (
                    <div className="flex items-center gap-1.5">
                        <input
                            ref={input}
                            type="file"
                            accept="image/png,image/svg+xml,image/webp,image/x-icon,image/vnd.microsoft.icon,.ico"
                            className="sr-only"
                            tabIndex={-1}
                            aria-hidden="true"
                            onChange={(event) => {
                                const file = event.target.files?.[0];

                                if (file) {
                                    upload(file);
                                }
                            }}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={busy}
                            onClick={() => input.current?.click()}
                        >
                            <Upload aria-hidden="true" />
                            {busy ? 'Uploading…' : 'Upload'}
                        </Button>
                        {(icon.mode !== 'automatic' || icon.url) && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label="More icon options"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {icon.mode !== 'automatic' && (
                                        <DropdownMenuItem
                                            onSelect={() => reset('automatic')}
                                        >
                                            Use the app’s favicon
                                        </DropdownMenuItem>
                                    )}
                                    {icon.mode !== 'none' && (
                                        <DropdownMenuItem
                                            onSelect={() => reset('none')}
                                        >
                                            Remove icon
                                        </DropdownMenuItem>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                )}
                <InputError message={error ?? undefined} />
            </div>
        </SettingsRow>
    );
}
