import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { ExternalLink, Trash2 } from 'lucide-react';
import DomainSetupInstructions from './DomainSetupInstructions';
import DomainValidationStatus from './DomainValidationStatus';
import { DomainValidationResult } from './types';

interface CustomDomainItemProps {
    domain: string;
    isOpen: boolean;
    onToggleOpen: () => void;
    onChangeDomain: (value: string) => void;
    onRemoveDomain: () => void;
    validation: Record<string, DomainValidationResult> | null;
    isValidating: boolean;
    serverIp: string;
}

export default function CustomDomainItem({
    domain,
    isOpen,
    onToggleOpen,
    onChangeDomain,
    onRemoveDomain,
    validation,
    isValidating,
    serverIp,
}: CustomDomainItemProps) {
    const isSslipDomain = domain.endsWith('sslip.io');

    return (
        <Collapsible open={isOpen} onOpenChange={onToggleOpen} className="rounded-md border border-gray-200">
            <div className="flex items-center gap-2 px-3 py-2">
                <Input value={domain} onChange={(e) => onChangeDomain(e.target.value)} className="flex-1" />
                <Button variant="ghost" size="icon" onClick={onRemoveDomain} type="button">
                    <Trash2 className="h-4 w-4 text-red-500" />
                </Button>
            </div>

            <div className="flex items-center justify-between border-t border-gray-100 bg-gray-50 px-3 py-2">
                <div className="flex items-center gap-2">
                    <a
                        href={domain.startsWith('http://') || domain.startsWith('https://') ? domain : `http://${domain}`}
                        target="_blank"
                        rel="noreferrer"
                        className="flex items-center space-x-2 text-xs text-blue-600 hover:underline"
                    >
                        <ExternalLink className="h-3 w-3" />
                        <span>Visit</span>
                    </a>
                    <DomainValidationStatus domain={domain} validation={validation} isValidating={isValidating} />
                </div>
                {!isSslipDomain && (
                    <CollapsibleTrigger asChild>
                        <Button variant="ghost" size="sm">
                            {isOpen ? 'Hide' : 'Setup'} Instructions
                        </Button>
                    </CollapsibleTrigger>
                )}
            </div>

            <CollapsibleContent className="border-t border-gray-100 bg-white">
                <DomainSetupInstructions domain={domain} serverIp={serverIp} />
            </CollapsibleContent>
        </Collapsible>
    );
}
