<?php

namespace App\Services;

use App\Models\Deployment;
use App\Services\DeploymentServices\MySqlDeploymentService;
use App\Services\DeploymentServices\RedisDeploymentService;
use App\Services\DeploymentTemplates\LaravelDeploymentTemplate;
use App\Services\DeploymentTemplates\NextjsDeploymentTemplate;

class DeploymentManager
{
    protected const TEMPLATES = [
        'nextjs' => NextjsDeploymentTemplate::class,
        'laravel' => LaravelDeploymentTemplate::class,
    ];

    public const SERVICES = [
        'mysql' => MySqlDeploymentService::class,
        'redis' => RedisDeploymentService::class,
    ];

    protected $templates;

    protected $activeTemplate;

    protected $github;

    public function __construct(protected Deployment $deployment)
    {
        $baseDirectory = $this->getBaseDirectory();
        $this->templates = collect(self::TEMPLATES)->map(fn ($template) => new $template($baseDirectory, $deployment));
        $this->github = new GitHub($deployment->environment->project->team);
    }

    protected function getBaseDirectory()
    {
        $folderName = $this->deployment->environment->project->slug.'-'.str_replace('/', '-', $this->deployment->environment->name);

        return storage_path('app/private/deployments/'.$folderName);
    }

    public function deploy()
    {
        $this->deployment->status = 'deploying';
        $this->deployment->started_at = now();
        $this->deployment->save();

        $this->pullLatestCode();

        $this->activeTemplate = $this->templates->first(fn ($template) => $template->isResponsible());

        if (! $this->activeTemplate) {
            $this->deployment->status = 'failed';
            $this->deployment->addLogSection('Error', 'No deployment template found for this codebase.');
            throw new \Exception('No deployment template found for this codebase.');
        } else {
            $this->deployment->environment->project->deployment_template = $this->activeTemplate::class;
            $this->deployment->environment->project->save();
        }
        $this->deployment->addLogSection('Deploying', 'Starting deployment with '.class_basename($this->activeTemplate));

        try {
            $this->activeTemplate->deploy();
        } catch (\Exception $e) {
            $this->deployment->addLogSection('Error', $e->getMessage());

            $this->deployment->status = 'failed';
            $this->deployment->finished_at = now();
            $this->deployment->save();

            throw $e;
        }

        $this->deployment->status = 'succeeded';
        $this->deployment->finished_at = now();
        $this->deployment->save();

        $this->deployment->environment->deployments()->where('id', '!=', $this->deployment->id)
            ->where('is_latest', true)
            ->update([
                'is_latest' => false,
            ]);
    }

    protected function pullLatestCode()
    {
        if (! file_exists($this->getBaseDirectory())) {
            // Download codebase for the first time
            $repoUrl = $this->deployment->environment->project->repository;
            $repoUrl = str_replace('https://github.com/', 'git@github.com:', $repoUrl); // Convert to SSH URL

            $cloneCode = "git clone {$this->github->getCliAuthParameter()} {$repoUrl} {$this->getBaseDirectory()} 2>&1";
            $output = shell_exec($cloneCode);

            $this->deployment->addLogSection('Clone Code', $output);
        } else {
            // Pull latest code
            $configInfo = $this->github->addSshConfigToRepository($this->getBaseDirectory());
            $this->deployment->addLogSection('Add SSH Config', $configInfo);

            $updateCode = "cd {$this->getBaseDirectory()} && git stash drop && git pull {$this->github->getCliAuthParameter()} 2>&1";
            $output = shell_exec($updateCode);

            $this->deployment->addLogSection('Update Code', $output);
        }

        $branch = escapeshellarg($this->deployment->environment->branch);
        $cloneCode = "cd {$this->getBaseDirectory()} && git switch {$branch} 2>&1";
        $output = shell_exec($cloneCode);

        $this->deployment->addLogSection('Switch branch', $output);
    }

    public function getLogs()
    {
        $logManager = new LogManager($this->deployment);

        return $logManager->getLogs();
    }

    public function getStats()
    {
        $docker = new Docker($this->getBaseDirectory(), $this->deployment);

        return $docker->getStats();
    }
}
