import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import FormErrors from '@/components/ui/form-errors';
import { Spinner } from '@/components/ui/spinner';
import SettingsLayout from '@/layouts/settings-layout';
import { SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { AlertCircle, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import AddDomainForm from './components/settings/AddDomainForm';
import CustomDomainList from './components/settings/CustomDomainList';
import DefaultDomain from './components/settings/DefaultDomain';
import { DomainValidationResult } from './components/settings/types';

interface PageProps extends SharedData {
    validation: Record<string, DomainValidationResult>;
    server_ip: string;
    is_public: boolean;
}

export default function DomainSettings() {
    const { currentTeam, currentProject, currentEnvironment, validation: initialValidation, server_ip, is_public } = usePage<PageProps>().props;
    const [isValidating, setIsValidating] = useState(false);
    const [validation, setValidation] = useState<Record<string, DomainValidationResult>>(initialValidation || {});
    const [openDomainIndex, setOpenDomainIndex] = useState<number | null>(null);
    const [newDomain, setNewDomain] = useState('');

    // Form to handle domain updates
    const form = useForm({
        domains: currentEnvironment?.domains || [],
    });

    const validateDomains = async () => {
        if (!currentTeam || !currentProject || !currentEnvironment) return;

        setIsValidating(true);
        try {
            const response = await fetch(
                route('teams.projects.environments.settings.domains.validate', {
                    team: currentTeam.slug,
                    project: currentProject.slug,
                    environment: currentEnvironment.id,
                }),
            );

            if (!response.ok) {
                throw new Error('Failed to validate domains');
            }

            const data = await response.json();
            setValidation(data.validation);
            toast.success('DNS records refreshed');
        } catch (error) {
            console.error('Domain validation error:', error);
            toast.error('Failed to validate domains');
        } finally {
            setIsValidating(false);
        }
    };

    const handleAddDomain = () => {
        if (!newDomain) return;

        const trimmedDomain = newDomain.trim();
        if (form.data.domains.includes(trimmedDomain)) {
            toast.error('Domain already exists', {
                description: 'This domain is already in the list.',
            });
            return;
        }

        form.setData('domains', [...form.data.domains, trimmedDomain]);
        setNewDomain('');
    };

    const handleRemoveDomain = (index: number) => {
        // Don't allow removing the first domain (default domain)
        if (index === 0) return;

        const newDomains = [...form.data.domains];
        newDomains.splice(index, 1);
        form.setData('domains', newDomains);
    };

    const handleChangeDomain = (index: number, value: string) => {
        const newDomains = [...form.data.domains];
        newDomains[index] = value;
        form.setData('domains', newDomains);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(
            route('teams.projects.environments.settings.domains.update', {
                team: currentTeam?.slug,
                project: currentProject?.slug,
                environment: currentEnvironment?.id,
            }),
            {
                onSuccess: () => {
                    validateDomains();
                },
            },
        );
    };

    const toggleDomainInstructions = (index: number) => {
        setOpenDomainIndex(openDomainIndex === index ? null : index);
    };

    return (
        <SettingsLayout title="Domain Settings" subtitle="Manage and configure custom domains for your environment">
            <Head title={`Domain Settings - ${currentProject?.name}`} />

            <Card>
                <CardHeader>
                    <CardTitle>Domains</CardTitle>
                    <CardDescription>Configure custom domains for your environment</CardDescription>
                </CardHeader>
                <CardContent>
                    {/* Server not publicly accessible warning */}
                    {!is_public && (
                        <Alert className="mb-4">
                            <AlertCircle className="h-4 w-4" />
                            <AlertTitle>Server Not Publicly Accessible</AlertTitle>
                            <AlertDescription>
                                Your server is not publicly accessible. DNS records cannot be validated, and external domains will not work until your
                                server is made public.
                            </AlertDescription>
                        </Alert>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-6">
                        {/* Domain List */}
                        <div className="space-y-4">
                            {/* Default domain (first in array) - cannot be edited */}
                            {form.data.domains.length > 0 && <DefaultDomain domain={form.data.domains[0]} />}

                            {/* Custom domains (can be edited) */}
                            <CustomDomainList
                                domains={form.data.domains}
                                openDomainIndex={openDomainIndex}
                                onToggleDomainInstructions={toggleDomainInstructions}
                                onChangeDomain={handleChangeDomain}
                                onRemoveDomain={handleRemoveDomain}
                                validation={validation}
                                isValidating={isValidating}
                                serverIp={server_ip}
                            />

                            {/* Add new domain */}
                            <AddDomainForm value={newDomain} onChange={setNewDomain} onAdd={handleAddDomain} />
                        </div>

                        <FormErrors errors={form.errors} />

                        <div className="flex justify-end gap-3">
                            <Button variant="outline" type="button" onClick={validateDomains} disabled={isValidating} className="ml-auto">
                                {isValidating ? <Spinner className="mr-2 h-4 w-4" /> : <RefreshCw className="mr-2 h-4 w-4" />}
                                Refresh DNS
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <Spinner className="mr-2 h-4 w-4" />}
                                Save Changes
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </SettingsLayout>
    );
}
