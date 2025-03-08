<?php

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('App.Models.Deployment.{deploymentId}', function (User $user, string $deploymentId) {
    $deployment = Deployment::find($deploymentId);

    return $deployment->environment->project->team->users->contains($user);
});

Broadcast::channel('App.Models.Project.{projectId}', function (User $user, string $projectId) {
    $project = Project::find($projectId);

    return $project->team->users->contains($user);
});
