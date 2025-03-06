<?php

namespace App\Services;

use App\Models\Deployment;
use App\Services\DeploymentServices\MySqlDeploymentService;
use App\Services\DeploymentTemplates\LaravelDeploymentTemplate;
use App\Services\DeploymentTemplates\NextjsDeploymentTemplate;

class DeploymentManager
{
    protected const TEMPLATES = [
        NextjsDeploymentTemplate::class,
        LaravelDeploymentTemplate::class,
    ];

    public const SERVICES = [
        'mysql' => MySqlDeploymentService::class,
    ];

    protected $templates;

    protected $activeTemplate;

    public function __construct(protected Deployment $deployment)
    {
        $baseDirectory = $this->getBaseDirectory();
        $this->templates = collect(self::TEMPLATES)->map(fn ($template) => new $template($baseDirectory, $deployment));
    }

    protected function getBaseDirectory()
    {
        $folderName = $this->deployment->environment->project->slug.'-'.$this->deployment->environment->name;

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
        // TODO: Handle auth for private repos
        // TODO: Switch branch

        if (! file_exists($this->getBaseDirectory())) {
            // Download codebase for the first time
            $cloneCode = "git clone {$this->deployment->environment->project->repository} {$this->getBaseDirectory()} 2>&1";
            $output = shell_exec($cloneCode);

            $this->deployment->addLogSection('Clone Code', $output);
        } else {
            // Pull latest code
            $updateCode = "cd {$this->getBaseDirectory()} && git pull 2>&1";
            $output = shell_exec($updateCode);

            $this->deployment->addLogSection('Update Code', $output);
        }
    }
}
