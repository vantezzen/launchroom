import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

/**
 * Staged production deployments (docs/06 §6.5): built and started with
 * production config, routed only when promoted.
 */
export function StageOption({
    checked,
    onChange,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex items-start gap-3">
            <Checkbox
                id="deploy-stage"
                className="mt-0.5"
                checked={checked}
                onCheckedChange={(next) => onChange(next === true)}
            />
            <div className="flex flex-col gap-0.5">
                <Label htmlFor="deploy-stage" className="type-body font-normal">
                    Stage without routing traffic
                </Label>
                <p className="type-small text-muted-foreground">
                    New versions are built and started next to the current ones.
                    Promote them from Deployments to switch traffic. Databases
                    deploy as usual.
                </p>
            </div>
        </div>
    );
}
