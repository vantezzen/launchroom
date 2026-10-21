<?php

declare(strict_types=1);

namespace App\Http\Controllers\Environments;

use App\Actions\Promotions\PromoteAndDeploy;
use App\Actions\Promotions\PromoteChanges;
use App\Data\Promotions\PromoteChangesData;
use App\Enums\EnvironmentKind;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Deployments\ScopesProjectRoutes;
use App\Http\Controllers\Projects\ProjectScope;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Queries\PromotionComparison;
use App\Queries\PromotionEnvironmentCards;
use App\Support\PublicId;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compare & promote (docs/06 §6, docs/08 §2 `/p/{project}/promote?from=&to=`).
 */
final class PromotionController extends Controller
{
    use ScopesProjectRoutes;

    public function show(Request $request, Project $project, PromotionComparison $comparison, PromotionEnvironmentCards $cards): Response
    {
        Gate::authorize('view', $project);

        $environments = $project->environments()
            ->orderByRaw("case kind when 'production' then 0 when 'persistent' then 1 else 2 end")
            ->orderBy('name')
            ->get();

        $from = $this->pick(array_values($environments->all()), $request->query('from'))
            ?? $environments->first(static fn (Environment $environment): bool => $environment->kind !== EnvironmentKind::Production);
        $to = $this->pick(array_values($environments->all()), $request->query('to'))
            ?? $environments->first(static fn (Environment $environment): bool => $environment->kind === EnvironmentKind::Production && $environment->id !== $from?->id)
            ?? $environments->first(static fn (Environment $environment): bool => $environment->id !== $from?->id);

        $from?->setRelation('project', $project);
        $to?->setRelation('project', $project);

        return Inertia::render('projects/promote', [
            ...ProjectScope::shellProps($project, $from),
            'comparison' => $from instanceof Environment && $to instanceof Environment && $from->id !== $to->id
                ? $comparison->get($project, $from, $to)
                : null,
            // Environment cards and "Promote and deploy".
            'cards' => $from instanceof Environment && $to instanceof Environment && $from->id !== $to->id
                ? ['from' => $cards->card($from), 'to' => $cards->card($to)]
                : null,
            'canDeploy' => $to instanceof Environment && Gate::allows('deploy', $to),
        ]);
    }

    /**
     * "Promote and deploy" (`deploy=1`, the primary action: stage and deploy
     * the target in one transaction) or "Stage only".
     */
    public function store(Request $request, Project $project, PromoteChangesData $data, #[CurrentUser] User $user, PromoteChanges $promote, PromoteAndDeploy $promoteAndDeploy): RedirectResponse
    {
        Gate::authorize('view', $project);
        $request->validate(['deploy' => ['sometimes', 'boolean']]);
        $from = $project->environments()->where('public_id', $data->from)->first();
        $to = $project->environments()->where('public_id', $data->to)->first();
        abort_unless($from instanceof Environment && $to instanceof Environment, 404);
        Gate::authorize('view', $from);
        Gate::authorize('promote', $to);

        if ($request->boolean('deploy')) {
            Gate::authorize('deploy', $to);

            [$promotion, $result] = $promoteAndDeploy->handle($project, $to, $data, $user, $this->auditActor($request, $user));
            broadcast(new CanvasChanged($to->public_id));

            $count = count($promotion->items) + count($promotion->images);
            $services = count($result->deployments);
            Inertia::flash('toast', ['type' => 'success', 'message' => "Promoted {$count} ".($count === 1 ? 'change' : 'changes')." from {$from->name}. "
                .($services === 0 ? "{$to->name} is up to date." : "Deploying {$services} ".($services === 1 ? 'service' : 'services')." in {$to->name}.")]);

            return to_route('deployments.project', ['project' => $project->public_id, 'environment' => $to->public_id]);
        }

        $promotion = $promote->handle($project, $data, $user, $this->auditActor($request, $user));
        broadcast(new CanvasChanged($to->public_id));

        $count = count($promotion->items);
        $images = count($promotion->images);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Staged {$count} ".($count === 1 ? 'change' : 'changes')
            .($images > 0 ? " and {$images} ".($images === 1 ? 'image' : 'images') : '')
            ." from {$from->name} in {$to->name}. Review and deploy them."]);

        return to_route('staged-changes.show', [$project->public_id, $to->public_id]);
    }

    /**
     * The environment switcher hint (docs/06 §6.1): "staging has 6 changes
     * that aren't in production". Production compares against nothing.
     */
    public function hint(Project $project, Environment $environment, PromotionComparison $comparison): JsonResponse
    {
        $this->scopeEnvironment($project, $environment);
        Gate::authorize('view', $environment);

        $production = $project->production()->first();

        if (! $production instanceof Environment || $environment->kind !== EnvironmentKind::Persistent) {
            return response()->json(['count' => 0, 'to' => null]);
        }

        $environment->setRelation('project', $project);
        $production->setRelation('project', $project);

        return response()->json([
            'count' => $comparison->count($environment, $production),
            'to' => ['id' => $production->public_id, 'name' => $production->name],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * @param  list<Environment>  $environments
     */
    private function pick(array $environments, mixed $id): ?Environment
    {
        if (! is_string($id) || ! PublicId::isValid($id, 'env')) {
            return null;
        }

        foreach ($environments as $environment) {
            if ($environment->public_id === $id) {
                return $environment;
            }
        }

        return null;
    }
}
