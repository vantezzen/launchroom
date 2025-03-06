import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { ExternalLink, Globe, Shield } from 'lucide-react';
import { SectionHeader, StatusIndicator } from './shared';

export default function NetworkSection({ domains, networkRef }: { domains: string[]; networkRef: React.MutableRefObject<HTMLDivElement | null> }) {
    return (
        <div className="z-10 space-y-4">
            <SectionHeader icon={<Globe className="h-4 w-4 text-gray-500" />} title="Network" />

            <Card ref={networkRef}>
                <CardTitle>Edge network</CardTitle>
                <CardContent className="space-y-4">
                    <EdgeNetworkItem icon={<Shield className="h-4 w-4 text-gray-500" />} label="Proxy Network" status="active" />
                    <EdgeNetworkItem icon={<Globe className="h-4 w-4 text-gray-500" />} label="Analytics" status="active" />
                </CardContent>
            </Card>

            <Card>
                <CardTitle>Domains</CardTitle>
                <CardContent>
                    {domains.map((domain, index) => (
                        <DomainItem key={index} domain={domain} />
                    ))}

                    <Button variant="ghost" className="w-full">
                        Add domain
                    </Button>
                </CardContent>
            </Card>
        </div>
    );
}

function EdgeNetworkItem({ icon, label, status }: { icon: React.ReactNode; label: string; status: 'active' | 'inactive' }) {
    return (
        <div className="flex items-center justify-between">
            <div className="flex items-center space-x-2">
                {icon}
                <span className="text-sm text-gray-600">{label}</span>
            </div>
            <StatusIndicator status={status} label="Active" />
        </div>
    );
}

function DomainItem({ domain }: { domain: string }) {
    return (
        <div className="flex items-center justify-between border-b border-gray-100 py-2 last:border-b-0">
            <a
                href={domain.startsWith('http://') || domain.startsWith('https://') ? domain : `http://${domain}`}
                target="_blank"
                rel="noreferrer"
                className="flex items-center space-x-3"
            >
                <ExternalLink className="h-4 w-4 text-gray-400" />
                <span className="flex-1 text-sm font-medium break-all text-gray-700">{domain}</span>
            </a>
        </div>
    );
}
