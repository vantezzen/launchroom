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
        $this->runCommand('docker compose up -d --remove-orphans --build --force-recreate');
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

    public function getLogs()
    {
        $command = "cd {$this->baseDirectory} && docker compose logs --tail=1000 -t";

        return shell_exec($command);
    }

    public function getStats()
    {
        $command = "cd {$this->baseDirectory} && docker compose stats --no-stream";
        $stats = shell_exec($command);
        $stats = $this->parseDockerStats($stats);

        return [
            'cpu' => array_sum(array_column($stats, 'cpu')),
            'mem_usage' => array_sum(array_column($stats, 'mem_usage')),
            'mem_limit' => $stats[0]['mem_limit'],
            'mem_percent' => array_sum(array_column($stats, 'mem_percent')),
            'net_in' => array_sum(array_column($stats, 'net_in')),
            'net_out' => array_sum(array_column($stats, 'net_out')),
            'block_in' => array_sum(array_column($stats, 'block_in')),
            'block_out' => array_sum(array_column($stats, 'block_out')),
        ];
    }

    protected function parseDockerStats($statsOutput)
    {
        $lines = explode("\n", trim($statsOutput)); // Split output into lines
        array_shift($lines); // Remove the header line

        $parsedStats = [];

        foreach ($lines as $line) {
            // Normalize whitespace to properly split columns
            $columns = preg_split('/\s{2,}/', trim($line));

            if (count($columns) < 7) {
                continue;
            } // Ensure there are enough columns

            $parsedStats[] = [
                'container_id' => $columns[0],
                'name' => $columns[1],
                'cpu' => (float) rtrim($columns[2], '%'),
                'mem_usage' => parseMemoryValue(explode(' / ', $columns[3])[0]), // Convert to bytes
                'mem_limit' => parseMemoryValue(explode(' / ', $columns[3])[1]),
                'mem_percent' => (float) rtrim($columns[4], '%'),
                'net_in' => parseMemoryValue(explode(' / ', $columns[5])[0]), // Convert KB/MB/GB
                'net_out' => parseMemoryValue(explode(' / ', $columns[5])[1]),
                'block_in' => parseMemoryValue(explode(' / ', $columns[6])[0]),
                'block_out' => parseMemoryValue(explode(' / ', $columns[6])[1]),
                'pids' => (int) $columns[7],
            ];
        }

        return $parsedStats;
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
