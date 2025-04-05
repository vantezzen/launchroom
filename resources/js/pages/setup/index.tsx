import AppLogoIcon from '@/components/laravel/app-logo-icon';
import { Button } from '@/components/ui/button';
import { defineStepper } from '@/components/ui/stepper';
import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import ConfirmStep from './steps/ConfirmStep';
import DashboardStep from './steps/DashboardStep';
import InstanceName from './steps/InstanceName';
import Introduction from './steps/Introduction';
import IpStep from './steps/IpStep';
import PublicStep from './steps/PublicStep';

export type SetupFormData = {
    name: string;
    ipv4: string;
    ipv6: string;
    domain: string;
    publicly_accessible: boolean;

    username: string;
    email: string;
    password: string;
    password_confirmation: string;
};

export type SetupForm = ReturnType<typeof useForm<SetupFormData>>;

const { StepperProvider, StepperControls, StepperDescription, StepperNavigation, StepperPanel, StepperStep, StepperTitle } = defineStepper(
    { id: 'introduction', title: 'Introduction', allowNext: (data: SetupFormData) => true },
    { id: 'instance', title: 'Instance', allowNext: (data: SetupFormData) => !!data.name },
    { id: 'ip', title: 'IP', allowNext: (data: SetupFormData) => !!data.ipv4 },
    { id: 'public', title: 'Public', allowNext: (data: SetupFormData) => true },
    {
        id: 'dashboard',
        title: 'Dashboard',
        allowNext: (data: SetupFormData) => {
            return (
                !!data.username &&
                !!data.password &&
                data.password.length >= 8 &&
                !!data.password_confirmation &&
                data.password === data.password_confirmation
            );
        },
    },
    { id: 'confirm', title: 'Confirm', allowNext: (data: SetupFormData) => true },
);

function SetupPage({ ipv4, ipv6, domain }: { ipv4: string; ipv6: string; domain: string }) {
    const form = useForm<SetupFormData>({
        name: 'launchroom',
        ipv4,
        ipv6,
        domain,
        publicly_accessible: true,

        username: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        form.post(route('setup.store'));
    };

    return (
        <div className="flex h-screen w-screen flex-col items-center justify-center p-4">
            <div className="w-lg">
                <AppLogoIcon className="mb-8 h-8 w-8" />
                <StepperProvider>
                    {({ methods }) => (
                        <>
                            <StepperNavigation>
                                {methods.all.map((step) => (
                                    <StepperStep key={step.id} of={step.id}></StepperStep>
                                ))}
                            </StepperNavigation>

                            <StepperPanel className="min-h-64 py-8">
                                {methods.switch({
                                    introduction: Introduction,
                                    instance: () => <InstanceName form={form} />,
                                    ip: () => <IpStep form={form} />,
                                    public: () => <PublicStep form={form} />,
                                    dashboard: () => <DashboardStep form={form} />,
                                    confirm: () => <ConfirmStep form={form} />,
                                    // done: () => <DoneStep form={form} />,
                                })}
                            </StepperPanel>

                            <StepperControls>
                                {!methods.isFirst && (
                                    <Button variant="secondary" onClick={methods.prev} disabled={methods.isFirst}>
                                        Previous
                                    </Button>
                                )}
                                {!methods.isLast ? (
                                    <Button onClick={methods.next} disabled={!methods.current.allowNext(form.data)}>
                                        Next
                                    </Button>
                                ) : (
                                    <Button type="submit" onClick={submit} disabled={form.processing}>
                                        Setup
                                    </Button>
                                )}
                            </StepperControls>
                        </>
                    )}
                </StepperProvider>
            </div>
        </div>
    );
}

export default SetupPage;
