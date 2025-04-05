<?php

namespace App\Models;

use App\Jobs\DeployJob;
use App\Services\GitHub;
use App\Traits\HasHashIds;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class Environment extends Model
{
    /** @use HasFactory<\Database\Factories\EnvironmentFactory> */
    use HasFactory;

    use HasHashIds;
    use HasRelationships;

    protected $fillable = ['project_id', 'name', 'type', 'domains', 'environment_variables', 'branch'];

    protected $casts = [
        'domains' => 'array',
        'environment_variables' => 'array',
    ];

    protected $hashPrefix = 'envr_';

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function deployments()
    {
        return $this->hasMany(Deployment::class)->orderByDesc('created_at');
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function processingUsages()
    {
        return $this->hasMany(ProcessingUsage::class);
    }

    public function team()
    {
        return $this->hasOneDeepFromReverse(
            (new Team)->environments()
        );
    }

    public function startDeployment($commitHash = null)
    {
        $project = $this->project;
        $team = $project->team;
        $github = new GitHub($team);
        $commitHash = $commitHash ?? $github->getLatestCommitHashInRepository($project->repository);

        $deployment = Deployment::create([
            'environment_id' => $this->id,
            'commit_hash' => $commitHash,
            'status' => 'pending',
            'output' => '',
            'is_latest' => true,
        ]);

        $deployment->addLogSection('Deployment started', 'The deployment process has started.');
        DeployJob::dispatch($deployment);

        return $deployment;
    }
}
