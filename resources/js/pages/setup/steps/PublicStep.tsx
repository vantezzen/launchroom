import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { SetupForm } from '..';

function PublicStep({ form }: { form: SetupForm }) {
    return (
        <div>
            <h1 className="mb-4 text-center text-2xl font-bold">Public Internet access</h1>
            <p className="mb-4 text-zinc-500">
                launchroom is primarily intended for servers that are accessible from the internet. If your server is not publicly accessible (e.g. if
                you are installing inside your local network), you can still use it, but some features like Webhook integrations and Let's Encrypt
                certificates are disabled.
            </p>

            <div className="mt-8 flex items-center space-x-2">
                <Switch
                    id="publicly_accessible"
                    checked={form.data.publicly_accessible}
                    onCheckedChange={(checked) => {
                        form.setData('publicly_accessible', checked);
                    }}
                />
                <Label htmlFor="publicly_accessible">Server can be reached from the internet</Label>
            </div>
        </div>
    );
}

export default PublicStep;
