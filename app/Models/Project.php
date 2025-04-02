<?php

namespace App\Models;

use App\Traits\HasHashIds;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;

    use HasHashIds;

    use HasRelationships;

    protected $fillable = ['name', 'description', 'team_id', 'slug', 'repository', 'branch', 'build_settings', 'deployment_template'];

    protected $hashPrefix = 'proj_';

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function environments()
    {
        return $this->hasMany(ProjectEnvironment::class);
    }

    public function deployments()
    {
        return $this->hasManyDeep(Deployment::class, [ProjectEnvironment::class]);
    }

    public function services()
    {
        return $this->hasManyDeep(Service::class, [ProjectEnvironment::class]);
    }
}
