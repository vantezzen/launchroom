<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Models\Project;
use App\Support\Images\IconImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Project icon files on the dashboard's private disk (never served
 * directly; ProjectIconController streams them after authorization). Paths
 * are generated from the project's public id and the content hash, never
 * from user input: `project-icons/prj_…/<hash>.<ext>`.
 */
final class ProjectIconStore
{
    public const string DISK = 'local';

    /**
     * Writes the file and returns the columns that point at it. The old
     * file is removed by {@see self::prune()} once the row is updated.
     *
     * @return array{icon_path: string, icon_type: string, icon_version: string}
     */
    public function put(Project $project, IconImage $icon): array
    {
        $version = $icon->version();
        $path = $this->directory($project)."/{$version}.{$icon->extension}";

        $this->disk()->put($path, $icon->bytes);

        return ['icon_path' => $path, 'icon_type' => $icon->extension, 'icon_version' => $version];
    }

    /**
     * Removes every file of the project except the current one.
     */
    public function prune(Project $project): void
    {
        foreach ($this->disk()->files($this->directory($project)) as $file) {
            if ($file !== $project->icon_path) {
                $this->disk()->delete($file);
            }
        }
    }

    public function read(Project $project): ?string
    {
        if ($project->icon_path === null || ! str_starts_with($project->icon_path, $this->directory($project).'/')) {
            return null;
        }

        return $this->disk()->get($project->icon_path);
    }

    public function deleteAll(Project $project): void
    {
        $this->disk()->deleteDirectory($this->directory($project));
    }

    private function directory(Project $project): string
    {
        return 'project-icons/'.$project->public_id;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
