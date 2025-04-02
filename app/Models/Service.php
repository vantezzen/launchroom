<?php

namespace App\Models;

use App\Traits\HasHashIds;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceFactory> */
    use HasFactory;

    use HasHashIds;

    protected $fillable = ['name', 'description', 'project_id', 'service_type', 'environment_variables', 'environment_types', 'category'];

    protected $casts = [
        'environment_variables' => 'array',
        'environment_types' => 'array',
    ];

    protected $hashPrefix = 'serv_';

    public function project_environment()
    {
        return $this->belongsTo(ProjectEnvironment::class);
    }

    public function team()
    {
        return $this->hasOneDeep(Team::class, [ProjectEnvironment::class, Project::class]);
    }

    public function project()
    {
        return $this->hasOneDeep(Project::class, [ProjectEnvironment::class]);
    }
}
