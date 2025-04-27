interface DomainSetupInstructionsProps {
    domain: string;
    serverIp: string;
}

export default function DomainSetupInstructions({ domain, serverIp }: DomainSetupInstructionsProps) {
    return (
        <div className="p-3 text-sm">
            <p className="mb-2 font-medium">How to set up your domain:</p>
            <ol className="ml-4 list-decimal space-y-1">
                <li>Go to your domain registrar (where you registered your domain)</li>
                <li>Find the DNS management section</li>
                <li>
                    Add an <strong>A record</strong> with the following settings
                </li>
                <li>
                    After you have created the A record, <strong>redeploy</strong> your application to activate the new domain
                </li>
            </ol>
            <div className="mt-2 rounded border bg-white p-2">
                <div className="grid grid-cols-2 gap-2">
                    <div className="font-medium">Host/Name:</div>
                    <div>{domain.includes('.') ? domain.split('.')[0] : '@'}</div>
                    <div className="font-medium">Type:</div>
                    <div>A</div>
                    <div className="font-medium">Value/Points to:</div>
                    <div>{serverIp || 'Loading...'}</div>
                    <div className="font-medium">TTL:</div>
                    <div>Automatic or 3600</div>
                </div>
            </div>
            <p className="mt-3 text-xs text-gray-500">
                <strong>Note:</strong> DNS changes can take up to 24-48 hours to propagate, though often they take effect much sooner. Click "Refresh
                DNS" to check if your DNS is properly configured.
            </p>
        </div>
    );
}
