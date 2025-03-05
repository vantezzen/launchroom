<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Team;
use App\Services\GitHub;
use Illuminate\Http\Request;

class GithubController extends Controller
{
    public function repositories(Team $team)
    {
        $github = new GitHub($team->github_token);
        return response()->json($github->getRepositories());
    }
}
