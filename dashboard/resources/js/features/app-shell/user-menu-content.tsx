import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowUpRight,
    Keyboard,
    LogOut,
    Megaphone,
    Monitor,
    Moon,
    Sun,
    UserRound,
} from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { openShortcuts } from '@/features/command-palette/command-palette';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { UserInfo } from './user-info';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';

type Props = {
    user: App.Data.Inertia.AuthUserData;
};

const themes: { value: Appearance; label: string; icon: typeof Sun }[] = [
    { value: 'system', label: 'System', icon: Monitor },
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
];

/**
 * The account menu (docs/08 §1, D114): you, your profile, the theme,
 * keyboard shortcuts, release notes and Log out. Docs and Settings sit in
 * the sidebar's bottom group right above, so they aren't repeated here.
 */
export function UserMenuContent({ user }: Props) {
    const { releasesUrl } = usePage().props;
    const { appearance, updateAppearance } = useAppearance();
    const current = themes.find((theme) => theme.value === appearance);

    // Radix leaves `pointer-events: none` on the body when a menu item navigates away.
    const cleanup = () => document.body.style.removeProperty('pointer-events');

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                    >
                        <UserRound className="mr-2" />
                        Profile
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger className="gap-2">
                        {current ? (
                            <current.icon className="mr-2 size-4 text-muted-foreground" />
                        ) : (
                            <Monitor className="mr-2 size-4 text-muted-foreground" />
                        )}
                        Theme
                        <span className="ml-auto type-small text-muted-foreground">
                            {current?.label}
                        </span>
                    </DropdownMenuSubTrigger>
                    <DropdownMenuSubContent className="min-w-36">
                        <DropdownMenuRadioGroup
                            value={appearance}
                            onValueChange={(value) =>
                                updateAppearance(value as Appearance)
                            }
                        >
                            {themes.map((theme) => (
                                <DropdownMenuRadioItem
                                    key={theme.value}
                                    value={theme.value}
                                >
                                    {theme.label}
                                </DropdownMenuRadioItem>
                            ))}
                        </DropdownMenuRadioGroup>
                    </DropdownMenuSubContent>
                </DropdownMenuSub>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem onSelect={() => openShortcuts()}>
                    <Keyboard className="mr-2" />
                    Keyboard shortcuts
                    <DropdownMenuShortcut>?</DropdownMenuShortcut>
                </DropdownMenuItem>
                {releasesUrl && (
                    <DropdownMenuItem asChild>
                        <a href={releasesUrl} target="_blank" rel="noreferrer">
                            <Megaphone className="mr-2" />
                            Release notes
                            <ArrowUpRight className="ml-auto text-muted-foreground" />
                        </a>
                    </DropdownMenuItem>
                )}
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    Log out
                </Link>
            </DropdownMenuItem>
        </>
    );
}
