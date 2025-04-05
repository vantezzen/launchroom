import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SetupForm } from '..';

function InstanceName({ form }: { form: SetupForm }) {
    return (
        <div>
            <h1 className="mb-4 text-center text-2xl font-bold">Give your instance a name</h1>
            <p className="mb-4 text-zinc-500">
                This name will be used to identify your instance in the dashboard. You can change it later if you want.
            </p>

            <Label htmlFor="instance-name">Instance Name</Label>
            <Input
                id="instance-name"
                placeholder="launchroom"
                value={form.data.name}
                onChange={(e) => {
                    form.setData('name', e.target.value);
                }}
            />
        </div>
    );
}

export default InstanceName;
