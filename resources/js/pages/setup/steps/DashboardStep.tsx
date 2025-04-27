import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SetupForm } from '..';

function DashboardStep({ form: { data, setData } }: { form: SetupForm }) {
    return (
        <div className="space-y-6">
            <h1 className="mb-4 text-center text-2xl font-bold">Dashboard access</h1>
            <p className="mb-4 text-zinc-500">
                Setup your admin account for the dashboard. This account will be used to log in to the dashboard and manage your applications.
            </p>

            <div className="grid gap-2">
                <Label htmlFor="name">Name</Label>
                <Input
                    id="name"
                    type="name"
                    required
                    tabIndex={2}
                    autoComplete="name"
                    value={data.username}
                    onChange={(e) => setData('username', e.target.value)}
                    placeholder="John Doe"
                />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    tabIndex={2}
                    autoComplete="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    placeholder="email@example.com"
                />

                <p className="text-sm text-zinc-500">This email will be used to log in to the dashboard but also for SSL certificate provisioning.</p>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="password">Password (min. 8 characters)</Label>
                <Input
                    id="password"
                    type="password"
                    required
                    tabIndex={3}
                    autoComplete="new-password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    placeholder="Password"
                />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="password_confirmation">Confirm password</Label>
                <Input
                    id="password_confirmation"
                    type="password"
                    required
                    tabIndex={4}
                    autoComplete="new-password"
                    value={data.password_confirmation}
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                    placeholder="Confirm password"
                />
            </div>
        </div>
    );
}

export default DashboardStep;
