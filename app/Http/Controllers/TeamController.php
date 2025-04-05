<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTeamRequest;
use App\Http\Requests\UpdateTeamRequest;
use App\Models\EncryptionKey;
use App\Models\Team;
use App\Services\GitHub;
use Inertia\Inertia;

class TeamController extends Controller
{
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('teams/create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTeamRequest $request)
    {
        $team = auth()->user->teams()->create($request->validated());
        EncryptionKey::newForTeam($team);

        return to_route('teams.show', $team);
    }

    /**
     * Display the specified resource.
     */
    public function show(Team $team)
    {
        return Inertia::render('teams/show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Team $team)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTeamRequest $request, Team $team)
    {
        $team->update($request->validated());

        if ($request->has('github_token')) {
            $team->github_token = $request->github_token;
            $github = new GitHub($team);
            $team->github_username = $github->getUsername();
            $team->save();

            $team->encryptionKey->addToGitHub();
        }

        return to_route('teams.show', $team);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Team $team)
    {
        //
    }
}
