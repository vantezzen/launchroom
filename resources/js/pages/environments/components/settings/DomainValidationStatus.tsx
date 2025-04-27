import { AlertCircle, InfoIcon, Loader2 } from 'lucide-react';
import { DomainValidationResult } from './types';

// Simple inline Spinner component
export const Spinner = ({ className = '', ...props }) => <Loader2 className={`animate-spin ${className}`} {...props} />;

interface DomainValidationStatusProps {
    domain: string;
    validation: Record<string, DomainValidationResult> | null;
    isValidating: boolean;
}

export default function DomainValidationStatus({ domain, validation, isValidating }: DomainValidationStatusProps) {
    if (isValidating) {
        return <Spinner className="h-3 w-3" />;
    }

    if (!validation) return null;

    const result = validation[domain];
    if (!result) return null;

    // Development domain (sslip.io)
    if (result.is_development) {
        return (
            <div className="flex items-center gap-2 text-sm text-green-600">
                <span>Development domain</span>
            </div>
        );
    }

    // Server not public
    if (result.error === 'server_not_public') {
        return (
            <div className="flex items-center gap-2 text-sm text-amber-600">
                <InfoIcon className="h-4 w-4" />
                <span>Server not publicly accessible</span>
            </div>
        );
    }

    // No DNS record
    if (result.error === 'no_dns_record') {
        return (
            <div className="flex items-center gap-2 text-sm text-red-600">
                <AlertCircle className="h-4 w-4" />
                <span>DNS record not found</span>
            </div>
        );
    }

    // Wrong IP
    if (result.error === 'wrong_ip') {
        return (
            <div className="flex items-center gap-2 text-sm text-red-600">
                <AlertCircle className="h-4 w-4" />
                <span>DNS points to wrong IP</span>
            </div>
        );
    }

    // Valid
    if (result.valid) {
        return (
            <div className="flex items-center gap-2 text-sm text-green-600">
                <span>DNS configured correctly</span>
            </div>
        );
    }

    return null;
}
