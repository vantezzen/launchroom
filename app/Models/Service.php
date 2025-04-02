<?php

namespace App\Models;

use App\Traits\HasHashIds;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class Service extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceFactory> */
    use HasFactory;

    use HasHashIds;
    use HasRelationships;

    protected $fillable = ['name', 'description', 'project_id', 'service_type', 'environment_variables', 'environment_types', 'category'];

    protected $casts = [
        'environment_variables' => 'array',
        'environment_types' => 'array',
    ];

    protected $hashPrefix = 'serv_';

    public function environment()
    {
        return $this->belongsTo(Environment::class);
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
}
