import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SetupForm } from '..';

function IpStep({ form }: { form: SetupForm }) {
    return (
        <div>
            <h1 className="mb-4 text-center text-2xl font-bold">Server IP</h1>
            <p className="mb-4 text-zinc-500">
                Please provide the IP address under which your server is reachable.
                <br />
                If you have a public IP, please use that - otherwise, use the private IP address of your server.
            </p>

            <Label htmlFor="ipv4">IPv4 address</Label>
            <Input
                id="ipv4"
                placeholder="192.168.1.1"
                value={form.data.ipv4}
                onChange={(e) => {
                    form.setData('ipv4', e.target.value);
                }}
                className="mb-4"
            />

            <Label htmlFor="ipv6">IPv6 address (optional)</Label>
            <Input
                id="ipv6"
                placeholder="2001:0db8:85a3:0000:0000:8a2e:0370:7334"
                value={form.data.ipv6}
                onChange={(e) => {
                    form.setData('ipv6', e.target.value);
                }}
            />
        </div>
    );
}

export default IpStep;
