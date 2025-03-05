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

    public function environment()
    {
        return $this->belongsTo(ProjectEnvironment::class, 'project_environment_id');
    }

    public function addLogSection($title, $content)
    {
        $this->refresh();
        $this->output .= "\n\n## $title\n\n$content";
        $this->save();
    }

    public function addLogText($content)
    {
        $this->refresh();
        $this->output .= "\n\n$content";
        $this->save();
    }
}
