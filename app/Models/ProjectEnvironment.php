<?php

namespace App\Models;

use App\Traits\HasHashIds;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectEnvironment extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectEnvironmentFactory> */
    use HasFactory;

    use HasHashIds;

    protected $fillable = ['project_id', 'name', 'type', 'domains', 'environment_variables'];

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
}
