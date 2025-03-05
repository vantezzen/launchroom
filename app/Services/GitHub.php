<?php

namespace App\Services;

use Github\AuthMethod;
use Github\Client;
use Github\ResultPager;
use Illuminate\Support\Facades\Cache;

class GitHub
{
    protected $client;

    protected $paginator;

    public function __construct(string $token)
    {
        $this->client = new Client;
        $this->authenticate($token);

        $this->paginator = new ResultPager($this->client);
    }

    public function authenticate($token)
    {
        $this->client->authenticate($token, null, AuthMethod::ACCESS_TOKEN);
    }

    public function getRepositories()
    {
        $currentUser = $this->getUsername();

        return Cache::remember('repositories::'.$currentUser, 3600, function () {
            $memberships = $this->client->currentUser()->memberships()->all();
            $repositories = $this->paginator->fetchAll($this->client->currentUser(), 'repositories');

            foreach ($memberships as $membership) {
                $repositories = array_merge($repositories, $this->paginator->fetchAll($this->client->organization(), 'repositories', [$membership['organization']['login']]));
            }

            return $repositories;
        });
    }

    public function getUsername()
    {
        return $this->client->currentUser()->show()['login'];
    }

    public function getLatestCommitHashInRepository($repositoryUrl)
    {
        // TODO
        return '123456';
    }

    public function getRepository($repositoryUrl)
    {
        $repository = explode('/', $repositoryUrl);

        return $this->client->repo()->show($repository[0], $repository[1]);
    }

    public function getRepositoryNamesFromUrl($repositoryUrl)
    {
        $repository = explode('/', $repositoryUrl);

        return [
            'owner' => $repository[0],
            'name' => $repository[1],
        ];
    }
}
