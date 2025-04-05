<?php

namespace App\Services;

use App\Models\Team;
use Github\AuthMethod;
use Github\Client;
use Github\ResultPager;
use Illuminate\Support\Facades\Cache;

class GitHub
{
    protected $client;

    protected $paginator;

    public function __construct(protected Team $team)
    {
        $this->client = new Client;
        $this->authenticate($team->github_token);

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
        $repositoryUrl = str_replace('https://github.com/', '', $repositoryUrl);
        $repository = explode('/', $repositoryUrl);

        return [
            'owner' => $repository[0],
            'name' => $repository[1],
        ];
    }

    public function getCliAuthParameter()
    {
        $path = $this->team->encryptionKey->storeInFilesystem();

        return "-c \"core.sshCommand=ssh -i {$path}\"";
    }

    public function addSshConfigToRepository($repositoryPath)
    {
        $path = $this->team->encryptionKey->storeInFilesystem();
        $configCommand = "cd {$repositoryPath} && git config core.sshCommand \"ssh -i {$path}\" 2>&1";
        $output = shell_exec($configCommand);

        return $output;
    }

    public function addSshKey($publicKey, $keyName)
    {
        $existingKey = $this->client->currentUser()->keys()->all();
        foreach ($existingKey as $key) {
            if ($key['title'] === $keyName) {
                dump('Key already exists');

                return;
            }
        }

        $this->client->currentUser()->keys()->create([
            'title' => $keyName,
            'key' => $publicKey,
        ]);
    }

    private function getWebhookUrl()
    {
        $isDev = config('app.env') === 'local';
        if ($isDev) {
            return config('services.smee.url');
        }

        return config('app.url').'/api/hooks/github';
    }

    public function setupWebhook($repositoryUrl)
    {
        $repository = $this->getRepositoryNamesFromUrl($repositoryUrl);

        $this->client->repo()->hooks()->create($repository['owner'], $repository['name'], [
            'name' => 'web',
            'config' => [
                'url' => $this->getWebhookUrl(),
                'content_type' => 'json',
                'secret' => config('app.key'),
                'insecure_ssl' => 1,
            ],
            'events' => ['push'],
            'active' => true,
        ]);
    }
}
