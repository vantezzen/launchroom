<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\GitHub;

class GithubController extends Controller
{
    public function repositories(Team $team)
    {
        $github = new GitHub($team);

        return response()->json($github->getRepositories());
    }
}
