<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Environment;
use Carbon\Carbon;

class LogManager
{
    protected string $baseDirectory;

    protected Deployment $deployment;

    protected Environment $environment;

    protected Docker $docker;

    /**
     * Create a new LogManager instance
     */
    public function __construct(Deployment $deployment)
    {
        $this->deployment = $deployment;
        $this->environment = $deployment->environment;
        $this->baseDirectory = $this->getBaseDirectory();
        $this->docker = new Docker($this->baseDirectory, $deployment);
    }

    /**
     * Get the base directory for the deployment
     */
    protected function getBaseDirectory(): string
    {
        $folderName = $this->deployment->environment->project->slug.'-'.str_replace('/', '-', $this->deployment->environment->name);

        return storage_path('app/private/deployments/'.$folderName);
    }

    /**
     * Get logs from all available sources
     */
    public function getLogs(): array
    {
        $logs = [];

        // Get Docker container logs
        $dockerLogs = $this->getDockerLogs();
        $logs = array_merge($logs, $dockerLogs);

        // Get Laravel logs
        $laravelLogs = $this->getLaravelLogs();
        $logs = array_merge($logs, $laravelLogs);

        // Sort all logs by timestamp
        usort($logs, function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        // Combine logs from the same source, service, and timestamp (rounded to the second)
        $combinedLogs = $this->combineLogsBySameSourceAndTimestamp($logs);

        return $combinedLogs;
    }

    /**
     * Combine logs that have the same source, service, and timestamp (rounded to the second)
     */
    protected function combineLogsBySameSourceAndTimestamp(array $logs): array
    {
        if (empty($logs)) {
            return [];
        }

        $combinedLogs = [];
        $currentGroup = null;

        foreach ($logs as $log) {
            // Round timestamp to the nearest second for comparison
            $roundedTimestamp = floor($log['timestamp']);

            // Create a unique key for each source+service+timestamp combination
            $key = $log['source'].'|'.$log['service'].'|'.$roundedTimestamp;

            if ($currentGroup &&
                $currentGroup['source'] === $log['source'] &&
                $currentGroup['service'] === $log['service'] &&
                floor($currentGroup['timestamp']) === $roundedTimestamp) {
                // This log entry belongs to the current group, append the message
                $currentGroup['message'] .= "\n".$log['message'];
            } else {
                // If we have a previous group, add it to the results
                if ($currentGroup) {
                    $combinedLogs[] = $currentGroup;
                }

                // Start a new group with this log entry
                $currentGroup = $log;
            }
        }

        // Add the last group if there is one
        if ($currentGroup) {
            $combinedLogs[] = $currentGroup;
        }

        return $combinedLogs;
    }

    /**
     * Parse Docker logs output into structured format
     */
    protected function getDockerLogs(): array
    {
        $dockerLogsRaw = $this->docker->getLogs();
        $dockerLines = explode("\n", $dockerLogsRaw);
        $logs = [];

        foreach ($dockerLines as $line) {
            if (empty($line)) {
                continue;
            }

            // Try to parse with full format (with service name)
            if (strpos($line, '|') !== false) {
                [$service, $rest] = explode('|', $line, 2);
            } else {
                $service = 'unknown';
                $rest = $line;
            }

            // Parse timestamp and message
            preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z|\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+|\S+) (.*)$/', trim($rest), $matches);

            if (count($matches) >= 3) {
                $timestampStr = $matches[1];
                $message = $matches[2];

                try {
                    $timestamp = Carbon::parse($timestampStr);
                } catch (\Exception $e) {
                    $timestamp = Carbon::now(); // Fallback to current time if parsing fails
                }

                $logs[] = [
                    'source' => 'docker',
                    'service' => trim($service),
                    'timestamp' => $timestamp->timestamp,
                    'raw_timestamp' => $timestampStr,
                    'message' => trim($message),
                    'level' => 'info',
                ];
            }
        }

        return $logs;
    }

    /**
     * Get Laravel logs from the app container
     */
    protected function getLaravelLogs(): array
    {
        $logs = [];

        // Execute command to check if the laravel.log file exists and get its contents
        $command = "cd {$this->baseDirectory} && docker compose exec -T app bash -c '[ -f /app/storage/logs/laravel.log ] && cat /app/storage/logs/laravel.log || echo \"File not found\"'";
        $output = shell_exec($command);

        if (! $output || strpos($output, 'File not found') !== false) {
            return $logs;
        }

        // Process Laravel log format
        $lines = explode("\n", $output);
        $currentEntry = null;
        $multilineMessage = [];

        foreach ($lines as $line) {
            // Check if this is a new log entry (starts with '[YYYY-MM-DD HH:MM:SS]')
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*)$/', $line, $matches)) {
                // If we have a previous entry being built, add it to the logs
                if ($currentEntry && ! empty($multilineMessage)) {
                    $currentEntry['message'] = implode("\n", $multilineMessage);
                    $logs[] = $currentEntry;
                    $multilineMessage = [];
                }

                // Extract timestamp, level, and start of message
                $timestampStr = $matches[1];
                $environment = $matches[2];
                $level = strtolower($matches[3]);
                $message = $matches[4];

                // Map Laravel log levels to our standardized levels
                $mappedLevel = $this->mapLaravelLogLevel($level);

                try {
                    $timestamp = Carbon::parse($timestampStr);
                } catch (\Exception $e) {
                    $timestamp = Carbon::now();
                }

                // Start a new entry
                $currentEntry = [
                    'source' => 'laravel',
                    'service' => 'app',
                    'timestamp' => $timestamp->timestamp,
                    'raw_timestamp' => $timestampStr,
                    'level' => $mappedLevel,
                    'environment' => $environment,
                ];

                $multilineMessage[] = $message;
            } elseif ($currentEntry) {
                // This is a continuation of the previous message
                $multilineMessage[] = $line;
            }
        }

        // Add the last entry if there is one
        if ($currentEntry && ! empty($multilineMessage)) {
            $currentEntry['message'] = implode("\n", $multilineMessage);
            $logs[] = $currentEntry;
        }

        return $logs;
    }

    /**
     * Map Laravel log levels to standardized levels
     */
    protected function mapLaravelLogLevel(string $level): string
    {
        $mapping = [
            'emergency' => 'error',
            'alert' => 'error',
            'critical' => 'error',
            'error' => 'error',
            'warning' => 'warning',
            'notice' => 'info',
            'info' => 'info',
            'debug' => 'debug',
        ];

        return $mapping[strtolower($level)] ?? 'info';
    }
}
