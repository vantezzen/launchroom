import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/patterns/page';
import { Wizard } from '@/components/patterns/wizard';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    ADD_SERVER_STEPS,
    MachineTiles,
    machine as findMachine,
    RolePicker,
} from '@/features/servers/add-server';
import type { Machine } from '@/features/servers/add-server';
import { RoleHints, roleHintKey } from '@/features/servers/role-hints';
import { create, index } from '@/routes/servers';
import { store } from '@/routes/servers/enrollments';

type Props = {
    roles: App.Enums.ServerRole[];
    serverCount: number;
    /** Keyed by roleHintKey(machine, roles). */
    roleHints: Record<string, App.Data.Servers.RoleHintData[]>;
};

function query(url: string): URLSearchParams {
    return new URLSearchParams(url.split('?')[1] ?? '');
}

/**
 * Add a server (flows §10, direction §9.13): pick the kind of machine,
 * what it should do, then one command and a live wait (the enrolment
 * page). The step is in the URL.
 */
export default function CreateServer({ serverCount, roleHints }: Props) {
    const { url } = usePage();
    const params = query(url);
    const picked = findMachine(params.get('machine'));
    const step = params.get('step') === 'roles' && picked ? 1 : 0;

    const form = useForm<{
        roles: App.Enums.ServerRole[];
        name: string;
        machine: Machine | null;
    }>({
        roles: picked?.roles ?? ['runtime'],
        name: '',
        machine: picked?.value ?? null,
    });

    const goTo = (next: 0 | 1, machine: Machine | null = form.data.machine) =>
        router.get(
            create().url,
            next === 1 && machine
                ? { step: 'roles', machine }
                : machine
                  ? { machine }
                  : {},
            { preserveState: true, preserveScroll: true, replace: false },
        );

    const chooseMachine = (value: Machine) => {
        const m = findMachine(value);
        form.setData((data) => ({
            ...data,
            machine: value,
            roles: m?.roles ?? data.roles,
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(store().url);
    };

    const machine = findMachine(form.data.machine);

    return (
        <>
            <Head title="Add a server" />

            <Page size="narrow">
                <PageHeader
                    title="Add a server"
                    description="Any Linux machine or Mac with Docker. It connects out, so there’s nothing to open."
                />

                <Wizard
                    steps={ADD_SERVER_STEPS.map((s, i) => ({
                        ...s,
                        summary: i === 0 && machine ? machine.label : undefined,
                    }))}
                    current={step}
                    onStepSelect={() => goTo(0)}
                    footer={
                        step === 0 ? (
                            <Button
                                type="button"
                                disabled={form.data.machine === null}
                                onClick={() => goTo(1)}
                            >
                                Continue
                                <ArrowRight aria-hidden="true" />
                            </Button>
                        ) : (
                            <Button
                                type="submit"
                                form="add-server"
                                disabled={
                                    form.processing ||
                                    form.data.roles.length === 0
                                }
                            >
                                {form.processing && <Spinner />}
                                Get the command
                                <ArrowRight aria-hidden="true" />
                            </Button>
                        )
                    }
                >
                    {step === 0 ? (
                        <div className="flex flex-col gap-4">
                            <div className="flex flex-col gap-1">
                                <h2 className="type-heading">
                                    What kind of machine is it?
                                </h2>
                                <p className="type-small text-muted-foreground">
                                    Only used for good defaults. The details are
                                    detected once it connects.
                                </p>
                            </div>
                            <MachineTiles
                                value={form.data.machine}
                                onChange={chooseMachine}
                            />
                        </div>
                    ) : (
                        <form
                            id="add-server"
                            onSubmit={submit}
                            className="flex flex-col gap-6"
                        >
                            <div className="flex flex-col gap-4">
                                <div className="flex flex-col gap-1">
                                    <h2 className="type-heading">
                                        What should it do?
                                    </h2>
                                    <p className="type-small text-muted-foreground">
                                        {machine?.hint}
                                    </p>
                                </div>
                                <RolePicker
                                    value={form.data.roles}
                                    onChange={(roles) =>
                                        form.setData('roles', roles)
                                    }
                                    limits={machine?.limits}
                                />
                                <InputError
                                    message={
                                        form.errors.roles ??
                                        form.errors['roles.0' as 'roles']
                                    }
                                />
                                <RoleHints
                                    hints={
                                        roleHints[
                                            roleHintKey(
                                                form.data.machine,
                                                form.data.roles,
                                            )
                                        ] ?? []
                                    }
                                />
                                {serverCount === 1 && (
                                    <p className="type-small text-muted-foreground">
                                        With a second server, your servers share
                                        a private network by themselves.
                                    </p>
                                )}
                            </div>
                            <div className="grid max-w-sm gap-1.5">
                                <label htmlFor="name" className="type-label">
                                    Name{' '}
                                    <span className="font-normal text-muted-foreground">
                                        (optional)
                                    </span>
                                </label>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    maxLength={64}
                                    autoComplete="off"
                                    placeholder="Its hostname"
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.name} />
                            </div>
                        </form>
                    )}
                </Wizard>
            </Page>
        </>
    );
}

CreateServer.layout = {
    breadcrumbs: [
        { title: 'Servers', href: index() },
        { title: 'Add server', href: create() },
    ],
};
