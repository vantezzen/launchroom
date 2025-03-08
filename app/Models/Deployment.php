<?php

namespace App\Models;

use App\Traits\HasHashIds;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deployment extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectDeploymentFactory> */
    use HasFactory;

    use HasHashIds;

    protected $fillable = ['project_environment_id', 'commit_hash', 'status', 'output', 'is_latest', 'started_at', 'finished_at'];

    protected $hashPrefix = 'depl_';

    protected $dispatchesEvents = [
        'updated' => \App\Events\DeploymentUpdated::class,
    ];

    public function environment()
    {
        return $this->belongsTo(ProjectEnvironment::class, 'project_environment_id');
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
