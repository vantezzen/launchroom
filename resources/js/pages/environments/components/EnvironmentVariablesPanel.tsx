import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { isRecordSame } from '@/utils/object';
import { Eye, EyeOff, X } from 'lucide-react';
import { useEffect, useState } from 'react';

interface EnvVar {
    key: string;
    value: string;
    isVisible?: boolean;
}

interface EnvironmentVariablePanelProps {
    isOpen: boolean;
    onClose: () => void;
    initialVariables: Record<string, string>;
    onSave?: (variables: Record<string, string>) => void;
    allowEdit?: boolean;
    title?: string;
    onChange?: (variables: Record<string, string>) => void;
}

export function EnvironmentVariablePanel({
    isOpen,
    onClose,
    initialVariables,
    onSave,
    allowEdit = true,
    title = 'Environment Variables',
    onChange = () => {},
}: EnvironmentVariablePanelProps) {
    const [variables, _internal_setVariables] = useState<EnvVar[]>([]);
    const setVariables = (vars: EnvVar[]) => {
        _internal_setVariables(vars);
        onChange(Object.fromEntries(vars.filter(({ key, value }) => key !== '' || value !== '').map(({ key, value }) => [key, value])));
    };

    useEffect(() => {
        const vars = Object.entries(initialVariables).map(([key, value]) => ({
            key,
            value,
            isVisible: false,
        }));

        // Always add an empty row at the end
        if (allowEdit) {
            vars.push({ key: '', value: '', isVisible: false });
        }

        setVariables(vars);
    }, [initialVariables, allowEdit]);

    const cleanVariables = (vars: EnvVar[]) => {
        const cleanedVars = vars.filter(({ key }) => key.trim() !== '');
        if (allowEdit) {
            cleanedVars.push({ key: '', value: '', isVisible: false });
        }
        return cleanedVars;
    };

    const handleRemoveVariable = (index: number) => {
        const newVariables = variables.filter((_, i) => i !== index);
        setVariables(cleanVariables(newVariables));
    };

    const handleChangeVariable = (index: number, field: 'key' | 'value', value: string) => {
        const newVariables = [...variables];
        newVariables[index][field] = value;
        setVariables(cleanVariables(newVariables));
    };

    const handlePaste = (e: React.ClipboardEvent<HTMLInputElement>, index: number, field: 'key' | 'value') => {
        if (field !== 'key') return;

        const pastedText = e.clipboardData.getData('text');
        const lines = pastedText.split(/\r?\n/);

        // Check if this looks like an env var format (contains = sign)
        if (lines.length >= 1 && pastedText.includes('=')) {
            e.preventDefault();

            const newVariables = [...variables];

            lines.forEach((line, lineIndex) => {
                if (!line.trim()) return;

                const parts = line.split('=');
                if (parts.length >= 2) {
                    const key = parts[0].trim();
                    const value = parts.slice(1).join('=').trim();

                    if (lineIndex === 0) {
                        newVariables[index] = { key, value, isVisible: false };
                    } else {
                        newVariables.push({ key, value, isVisible: false });
                    }
                }
            });
            setVariables(cleanVariables(newVariables));
        }
    };

    const toggleVisibility = (index: number) => {
        const newVariables = [...variables];
        newVariables[index].isVisible = !newVariables[index].isVisible;
        setVariables(newVariables);
    };

    const handleSave = () => {
        if (!onSave) return;
        const variablesObject = variables
            .filter(({ key }) => key.trim() !== '')
            .reduce(
                (acc, { key, value }) => {
                    acc[key] = value;
                    return acc;
                },
                {} as Record<string, string>,
            );

        onSave(variablesObject);
        onClose();
    };

    return (
        <Sheet
            open={isOpen}
            onOpenChange={() => {
                const hasChanges = !isRecordSame(
                    Object.fromEntries(variables.filter(({ key, value }) => key !== '' || value !== '').map(({ key, value }) => [key, value])),
                    initialVariables,
                );
                if (hasChanges && allowEdit) {
                    if (confirm('You have unsaved changes. Do you want to discard them?')) {
                        onClose();
                    }
                } else {
                    onClose();
                }
            }}
        >
            <SheetContent className="w-[50vw] sm:max-w-none">
                <SheetHeader>
                    <SheetTitle>{title}</SheetTitle>
                </SheetHeader>

                <div className="p-6">
                    <ScrollArea className="h-[calc(100vh-180px)]">
                        <div className="space-y-4 p-2">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="text-sm font-medium">Key</div>
                                <div className="text-sm font-medium">Value</div>
                            </div>

                            {variables.map((variable, index) => (
                                <div key={index} className="grid grid-cols-2 items-center gap-2">
                                    <Input
                                        placeholder="e.g. CLIENT_KEY"
                                        value={variable.key}
                                        onChange={(e) => handleChangeVariable(index, 'key', e.target.value)}
                                        onPaste={(e) => handlePaste(e, index, 'key')}
                                        disabled={!allowEdit}
                                    />
                                    <div className="relative flex items-center gap-2">
                                        <Input
                                            type={variable.isVisible ? 'text' : 'password'}
                                            value={variable.value}
                                            onChange={(e) => handleChangeVariable(index, 'value', e.target.value)}
                                            disabled={!allowEdit}
                                        />
                                        <Button variant="ghost" size="icon" onClick={() => toggleVisibility(index)}>
                                            {variable.isVisible ? (
                                                <EyeOff className="h-4 w-4 text-gray-500" />
                                            ) : (
                                                <Eye className="h-4 w-4 text-gray-500" />
                                            )}
                                        </Button>
                                        {allowEdit && variable.key !== '' && (
                                            <Button variant="ghost" size="icon" onClick={() => handleRemoveVariable(index)}>
                                                <X className="h-4 w-4 text-gray-500" />
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ))}

                            {allowEdit && (
                                <div className="mt-4 text-sm text-gray-500">
                                    Paste values like <code>KEY=value</code> to automatically split them
                                </div>
                            )}
                        </div>
                    </ScrollArea>
                </div>

                {allowEdit && (
                    <div className="absolute right-0 bottom-0 left-0 border-t bg-white p-4">
                        <div className="flex justify-end space-x-2">
                            <Button variant="outline" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button onClick={handleSave}>Save</Button>
                        </div>
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}
