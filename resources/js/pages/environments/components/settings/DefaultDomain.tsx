import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Trash2 } from 'lucide-react';

interface DefaultDomainProps {
    domain: string;
}

export default function DefaultDomain({ domain }: DefaultDomainProps) {
    return (
        <div className="space-y-2">
            <h3 className="text-sm font-medium text-zinc-700 dark:text-zinc-500">Default Domain</h3>
            <div className="flex items-center gap-2">
                <Input value={domain} disabled className="flex-1" />
                <Button variant="ghost" size="icon" disabled>
                    <Trash2 className="h-4 w-4 text-zinc-300" />
                </Button>
            </div>
        </div>
    );
}
