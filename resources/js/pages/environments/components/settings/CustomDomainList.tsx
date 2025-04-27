import CustomDomainItem from './CustomDomainItem';
import { DomainValidationResult } from './types';

interface CustomDomainListProps {
    domains: string[];
    openDomainIndex: number | null;
    onToggleDomainInstructions: (index: number) => void;
    onChangeDomain: (index: number, value: string) => void;
    onRemoveDomain: (index: number) => void;
    validation: Record<string, DomainValidationResult> | null;
    isValidating: boolean;
    serverIp: string;
}

export default function CustomDomainList({
    domains,
    openDomainIndex,
    onToggleDomainInstructions,
    onChangeDomain,
    onRemoveDomain,
    validation,
    isValidating,
    serverIp,
}: CustomDomainListProps) {
    if (domains.length < 2) {
        return null;
    }

    return (
        <div className="space-y-2">
            <h3 className="text-sm font-medium text-gray-700">Custom Domains</h3>
            <div className="space-y-2">
                {domains.slice(1).map((domain, index) => {
                    const realIndex = index + 1;

                    return (
                        <CustomDomainItem
                            key={realIndex}
                            domain={domain}
                            isOpen={openDomainIndex === realIndex}
                            onToggleOpen={() => onToggleDomainInstructions(realIndex)}
                            onChangeDomain={(value) => onChangeDomain(realIndex, value)}
                            onRemoveDomain={() => onRemoveDomain(realIndex)}
                            validation={validation}
                            isValidating={isValidating}
                            serverIp={serverIp}
                        />
                    );
                })}
            </div>
        </div>
    );
}
