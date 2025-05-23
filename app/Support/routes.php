<?php

use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;

function frontendRoute(string $route, $model)
{
    $parameters = [];
    if (is_null($model)) {
        throw new Exception('Cannot get frontend route for null model');
    } elseif (is_string($model)) {
        throw new Exception('Cannot get frontend route for string - make sure to pass the model');
    } elseif ($model instanceof Team) {
        $parameters = ['team' => $model->slug];
    } elseif ($model instanceof Project) {
        $parameters = ['team' => $model->team->slug, 'project' => $model->slug];
    } elseif ($model instanceof Environment) {
        $parameters = ['team' => $model->team->slug, 'project' => $model->project->slug, 'environment' => $model->id];
    } elseif ($model instanceof Deployment) {
        $parameters = ['team' => $model->team->slug, 'project' => $model->project->slug, 'environment' => $model->environment_id, 'deployment' => $model->id];
    } elseif ($model instanceof Service) {
        $parameters = ['team' => $model->team->slug, 'project' => $model->project->slug, 'environment' => $model->environment_id, 'service' => $model->id];
    }

    return route($route, $parameters);
}
