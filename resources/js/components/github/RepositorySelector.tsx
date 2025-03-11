import { Button } from '@/components/ui/button';
import { Command, CommandGroup, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { GithubOwner, Repository } from '@/types';
import { timeAgo } from '@/utils/time';
import { Check, ChevronDown, Lock, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Input } from '../ui/input';
import { LanguageIcon } from './LanguageIcon';

export type UrlRepository = {
    name: string;
    html_url: string;
    full_name: string;
};

function RepositorySelector({ repositories, onSelect }: { repositories: Repository[]; onSelect: (repository: Repository | UrlRepository) => void }) {
    const [search, setSearch] = useState('');
    const [selectedOwner, setSelectedOwner] = useState<GithubOwner | null>(null);
    const [ownerPopoverOpen, setOwnerPopoverOpen] = useState(false);
    const [visibleCount, setVisibleCount] = useState(6);
    const [directUrl, setDirectUrl] = useState('');

    // Extract unique owners from repository list
    const owners = useMemo(() => {
        const ownerMap = new Map<string, GithubOwner>();
        repositories.forEach((repo) => {
            if (!ownerMap.has(repo.owner.login)) {
                ownerMap.set(repo.owner.login, repo.owner);
            }
        });
        return Array.from(ownerMap.values());
    }, [repositories]);

    // Filter repositories based on selected owner and search term
    const filteredRepositories = useMemo(() => {
        return repositories
            .filter((repo) => {
                const matchesOwner = !selectedOwner || repo.owner.login === selectedOwner.login;
                const matchesSearch =
                    !search ||
                    repo.name.toLowerCase().includes(search.toLowerCase()) ||
                    (repo.description && repo.description.toLowerCase().includes(search.toLowerCase()));

                return matchesOwner && matchesSearch;
            })
            .sort((a, b) => new Date(b.updated_at).getTime() - new Date(a.updated_at).getTime());
    }, [repositories, selectedOwner, search]);

    return (
        <div className="w-full space-y-4">
            <div className="flex gap-2">
                {/* Owner selector */}
                <Popover open={ownerPopoverOpen} onOpenChange={setOwnerPopoverOpen}>
                    <PopoverTrigger asChild>
                        <Button variant="outline" role="combobox" aria-expanded={ownerPopoverOpen} className="flex w-[200px] justify-between">
                            {selectedOwner ? (
                                <div className="flex items-center gap-2">
                                    <img
                                        src={selectedOwner.avatar_url || '/placeholder.svg'}
                                        alt={`${selectedOwner.login} avatar`}
                                        className="h-5 w-5 rounded-full"
                                    />
                                    <span>{selectedOwner.login}</span>
                                </div>
                            ) : (
                                <span>Select owner</span>
                            )}
                            <ChevronDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-[180px] p-0">
                        <Command>
                            <CommandList>
                                <CommandGroup>
                                    {owners.map((owner) => (
                                        <CommandItem
                                            key={owner.login}
                                            value={owner.login}
                                            onSelect={() => {
                                                setSelectedOwner(selectedOwner?.login === owner.login ? null : owner);
                                                setOwnerPopoverOpen(false);
                                            }}
                                            className="flex items-center gap-2"
                                        >
                                            <img
                                                src={owner.avatar_url || '/placeholder.svg'}
                                                alt={`${owner.login} avatar`}
                                                className="h-5 w-5 rounded-full"
                                            />
                                            <span>{owner.login}</span>
                                            <Check
                                                className={cn('ml-auto h-4 w-4', selectedOwner?.login === owner.login ? 'opacity-100' : 'opacity-0')}
                                            />
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            </CommandList>
                        </Command>
                    </PopoverContent>
                </Popover>

                {/* Search input */}
                <div className="relative flex-1">
                    <Search className="text-muted-foreground absolute top-2.5 left-2.5 h-4 w-4" />
                    <input
                        type="text"
                        placeholder="Search..."
                        className="border-input bg-background ring-offset-background focus-visible:ring-ring h-10 w-full rounded-md border py-2 pr-3 pl-8 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
            </div>
            {/* Repository list */}
            <div className="rounded-md border">
                {filteredRepositories.length === 0 ? (
                    <div className="text-muted-foreground p-6 text-center">No repositories found</div>
                ) : (
                    <>
                        <div className="grid grid-cols-1 divide-y lg:grid-cols-2 lg:divide-x lg:divide-y-0">
                            {filteredRepositories.slice(0, visibleCount).map((repo) => (
                                <div key={repo.id} className={cn('hover:bg-muted/50 flex items-center justify-between p-4 lg:border-b')}>
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-4 w-4 items-center justify-center">
                                            <LanguageIcon language={repo.language} />
                                        </div>

                                        <div className="flex flex-col">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">{repo.full_name}</span>
                                                {repo.private && <Lock className="text-muted-foreground h-3.5 w-3.5" />}
                                            </div>
                                            <div className="text-muted-foreground text-xs">{timeAgo(repo.updated_at)}</div>
                                        </div>
                                    </div>

                                    <Button variant="default" size="sm" onClick={() => onSelect(repo)}>
                                        Select
                                    </Button>
                                </div>
                            ))}
                        </div>

                        {filteredRepositories.length > visibleCount && (
                            <div className="border-t p-4 text-center">
                                <Button variant="outline" onClick={() => setVisibleCount((prev) => prev + 6)}>
                                    Show More
                                </Button>
                            </div>
                        )}
                    </>
                )}
            </div>

            <div className="flex items-center">
                <div className="flex-grow border-t border-gray-200"></div>
                <span className="mx-4 text-gray-300">or</span>
                <div className="flex-grow border-t border-gray-200"></div>
            </div>

            <div className="flex items-center gap-3">
                <Input
                    placeholder="https://github.com/vantezzen/launchroom"
                    type="url"
                    value={directUrl}
                    onChange={(e) => setDirectUrl(e.target.value)}
                />
                <Button
                    onClick={() => {
                        const repoRegex = /https:\/\/github\.com\/(?<name>\w+)\/(?<repo>\w+)\/?.*/;

                        const match = directUrl.match(repoRegex);
                        if (match) {
                            onSelect({
                                full_name: `${match.groups?.name}/${match.groups?.repo}`,
                                name: match.groups?.repo || '',
                                html_url: directUrl,
                            });
                        } else {
                            alert(
                                'This does not look like a valid GitHub repository URL. Please make sure to use a valid URL i the format "https://github.com/vantezzen/launchroom".',
                            );
                        }
                    }}
                >
                    Use URL
                </Button>
            </div>
        </div>
    );
}

export default RepositorySelector;
