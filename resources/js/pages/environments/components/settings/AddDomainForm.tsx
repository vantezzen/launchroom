import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Plus } from 'lucide-react';

interface AddDomainFormProps {
    value: string;
    onChange: (value: string) => void;
    onAdd: () => void;
}

export default function AddDomainForm({ value, onChange, onAdd }: AddDomainFormProps) {
    const handleKeyPress = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            onAdd();
        }
    };

    return (
        <div className="space-y-2">
            <h3 className="text-sm font-medium text-gray-700">Add New Domain</h3>
            <div className="flex items-center gap-2">
                <Input
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder="example.com"
                    className="flex-1"
                    onKeyPress={handleKeyPress}
                />
                <Button onClick={onAdd} type="button">
                    <Plus className="mr-2 h-4 w-4" />
                    Add
                </Button>
            </div>
        </div>
    );
}
