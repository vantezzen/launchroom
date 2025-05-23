<?php

namespace App\Models;

use App\Events\DeploymentUpdated;
use App\Traits\HasHashIds;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class Deployment extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectDeploymentFactory> */
    use HasFactory;

    use HasHashIds;
    use HasRelationships;

    protected $fillable = ['environment_id', 'commit_hash', 'status', 'output', 'is_latest', 'started_at', 'finished_at'];

    protected $hashPrefix = 'depl_';

    protected static function boot()
    {
        parent::boot();

        // Debounce DeploymentUpdated event to prevent sending hundreds of events per deployment
        static::updated(function ($deployment) {
            $cacheKey = 'deployment-updated-'.$deployment->id;

            if (! Cache::has($cacheKey) || $deployment->wasChanged('status')) {
                Cache::put($cacheKey, true, 5);
                event(new DeploymentUpdated($deployment));
            }
        });
    }

    public function environment()
    {
        return $this->belongsTo(Environment::class, 'environment_id');
    }

    public function team()
    {
        return $this->hasOneDeepFromReverse(
            (new Team)->deployments()
        );
    }

    public function project()
    {
        return $this->hasOneDeepFromReverse(
            (new Project)->deployments()
        );
    }

    public function addLogSection($title, $content, $prefix = 'log')
    {
        $this->refresh();
        $this->output .= $this->addPrefixToAllLines("## $title\n$content\n", $prefix);
        $this->save();
    }

    public function addLogText($content, $prefix = 'log')
    {
        $this->refresh();
        $this->output .= $this->addPrefixToAllLines($content."\n", $prefix);
        $this->save();
    }

    private function addPrefixToAllLines($content, $prefix)
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $fullPrefix = "[$timestamp][$prefix] ";

        return collect(explode("\n", $content))
            ->filter(fn ($line) => ! empty($line))
            ->map(fn ($line) => $fullPrefix.$line."\n")
            ->implode("\n");
    }
}
