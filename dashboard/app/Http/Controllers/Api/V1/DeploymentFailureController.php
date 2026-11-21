<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Deployments\ApplyFailureFix;
use App\Data\Api\ApiFailureFixResultData;
use App\Data\Api\ApiFailureReportData;
use App\Data\Api\ApplyFailureFixInput;
use App\Data\Deployments\FailureExplanationData;
use App\Data\Deployments\FailureFixData;
use App\Data\Specs\ServiceSpec;
use App\Enums\DeploymentStatus;
use App\Enums\FailureFixAction;
use App\Enums\FailureFixOutcome;
use App\Exceptions\DomainRuleViolation;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\User;
use App\Queries\Api\ApiResources;
use App\Queries\Api\DeploymentFailureReport;
use App\Queries\FailureExplanations;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A failed deployment explained, and its suggested fix applied (docs/04 §5,
 * docs/16 §2.2, D111): what the deployment page's failure card says and
 * does, with the same authorization as its "Fix and redeploy" button.
 */
final class DeploymentFailureController extends Controller
{
    #[ApiOperation(id: 'getDeploymentFailure', summary: 'Why a deployment failed, and how to fix it', tag: 'Deployments', response: ApiFailureReportData::class, description: 'The explanation the dashboard shows: the known failure in plain words, the build\'s hint codes, the failing step with its last lines (secrets masked) and the fixes. `rule_violation` when the deployment did not fail.')]
    public function show(Deployment $deployment, DeploymentFailureReport $reports): JsonResponse
    {
        Gate::authorize('view', $deployment);

        $report = $reports->get($deployment) ?? throw DomainRuleViolation::on('deployment', 'This deployment didn’t fail.');

        return ApiResponse::item($report);
    }

    #[ApiOperation(id: 'applyDeploymentFix', summary: 'Apply the fix suggested for a failed deployment', tag: 'Deployments', response: ApiFailureFixResultData::class, request: ApplyFailureFixInput::class, status: 202, description: 'Only the one-click fix listed in `getDeploymentFailure` (`fixes[].oneClick`). It stages the setting and deploys it when nothing else is staged; with other staged changes it only stages (`outcome: staged`). `free_disk` frees space on the server and deploys nothing. Needs the `deploy` ability too, and the role to change the service\'s settings.')]
    public function fix(Request $request, Deployment $deployment, FailureFixAction $code, ApplyFailureFixInput $input, #[CurrentUser] User $user, FailureExplanations $explanations, ApplyFailureFix $apply, ApiResources $resources): JsonResponse
    {
        $deployment->loadMissing(['serviceInstance.service', 'environment', 'revision']);
        Gate::authorize('update', $deployment->serviceInstance);
        Gate::authorize('deploy', $deployment->environment);

        $explanation = $this->offered($deployment, $code, $explanations);
        $params = $explanation->params;
        $port = $code === FailureFixAction::SetPort ? ($input->port ?? $this->int($params['port'] ?? null)) : null;

        if ($code === FailureFixAction::SetPort && $port === null) {
            throw DomainRuleViolation::on('port', 'launchroom didn’t find the port the app listens on. Pass `port`.');
        }

        $created = $apply->handle($deployment, new FailureFixData(
            action: $code,
            port: $port,
            seconds: $code === FailureFixAction::RaiseHealthTimeout ? ($input->seconds ?? $this->int($params['suggestedSeconds'] ?? null) ?? 60) : null,
            server: $code === FailureFixAction::FreeDisk && isset($params['server']) ? (string) $params['server'] : null,
        ), $user, $this->auditActor($request, $user));

        $outcome = match (true) {
            $code === FailureFixAction::FreeDisk => FailureFixOutcome::FreeingDisk,
            $created instanceof Deployment => FailureFixOutcome::Deploying,
            default => FailureFixOutcome::Staged,
        };

        return ApiResponse::item(new ApiFailureFixResultData(
            outcome: $outcome,
            message: $outcome->message(),
            deployment: $created instanceof Deployment ? $resources->deployment($created) : null,
        ), 202);
    }

    /**
     * The explanation that offers `$code` as its one-click fix. A fix the
     * catalogue doesn't suggest for this failure is refused: the API never
     * changes a setting on a guess.
     */
    private function offered(Deployment $deployment, FailureFixAction $code, FailureExplanations $explanations): FailureExplanationData
    {
        if ($deployment->status !== DeploymentStatus::Failed) {
            throw DomainRuleViolation::on('deployment', 'This deployment didn’t fail.');
        }

        $service = $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);
        $explanation = $explanations->explain($deployment, $service instanceof ServiceSpec ? $service : null);

        if (! $explanation instanceof FailureExplanationData || $explanation->action !== $code) {
            throw DomainRuleViolation::on('code', 'launchroom doesn’t suggest this fix for this deployment.');
        }

        if (! $code->isOneClick()) {
            throw DomainRuleViolation::on('code', 'This fix needs the dashboard: open the deployment to apply it.');
        }

        return $explanation;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
