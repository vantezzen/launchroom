<?php

namespace App\Services;

use App\Models\Deployment;
use RuntimeException;

class Docker
{
    public function __construct(protected string $baseDirectory, protected Deployment $deployment) {}

    public function build()
    {
        $this->deployment->addLogSection('Build', "Building Docker images...\n\n");
        $this->runCommand('docker compose build');
    }

    public function start()
    {
        $this->deployment->addLogSection('Start', "Starting Docker images...\n\n");
        $this->runCommand('docker compose up -d --remove-orphans --build');
    }

    public function stop()
    {
        $this->deployment->addLogSection('Stop', "Stopping Docker images...\n\n");
        $this->runCommand('docker compose stop');
    }

    public function destroy()
    {
        $this->deployment->addLogSection('Destroy', "Destroying Docker images...\n\n");
        $this->runCommand('docker compose down --volumes --remove-orphans');
    }

    protected function runCommand($dockerCommand)
    {
        $command = "cd {$this->baseDirectory} && $dockerCommand";

        // Setup process descriptors for stdout and stderr
        $descriptors = [
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        // Open the process
        $process = proc_open($command, $descriptors, $pipes);

        if (! is_resource($process)) {
            $this->deployment->addLogText("Failed to execute command: $command");
            throw new RuntimeException("Failed to execute command: $command");
        }

        $output = '';
        $errorOutput = '';

        // Read output and error streams in real-time
        while (! feof($pipes[1]) || ! feof($pipes[2])) {
            $stdout = fgets($pipes[1]);
            $stderr = fgets($pipes[2]);

            if ($stdout !== false) {
                $output .= $stdout;
                $this->deployment->addLogText($stdout);
            }

            if ($stderr !== false) {
                $errorOutput .= $stderr;
                $this->deployment->addLogText($stderr, 'error');
            }

            flush(); // Ensure real-time output is displayed/logged
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $this->deployment->addLogText("Command failed with exit code $exitCode.");
            throw new RuntimeException("Docker build failed with exit code $exitCode.\nError Output:\n$errorOutput");
        }

        $this->deployment->addLogText('Command executed successfully.');
    }
}
