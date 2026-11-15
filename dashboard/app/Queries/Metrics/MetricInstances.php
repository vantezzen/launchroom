<?php

declare(strict_types=1);

namespace App\Queries\Metrics;

use App\Support\Metrics\Cast;
use Illuminate\Support\Facades\DB;

final class MetricInstances
{
    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, service_public_id: string, service_name: string, environment_public_id: string, environment_name: string, project_public_id: string, project_name: string, color_slot: int}>
     */
    public static function named(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('service_instances as si')
            ->join('services as s', 's.id', '=', 'si.service_id')
            ->join('environments as e', 'e.id', '=', 'si.environment_id')
            ->join('projects as p', 'p.id', '=', 'e.project_id')
            ->whereIn('si.id', $ids)
            ->orderBy('s.name')
            ->get(['si.id', 's.public_id as service_public_id', 's.name as service_name', 'e.public_id as environment_public_id', 'e.name as environment_name', 'p.public_id as project_public_id', 'p.name as project_name', 'p.color_slot'])
            ->map(static fn (object $row): array => [
                'id' => Cast::id($row->id ?? null),
                'service_public_id' => Cast::string($row->service_public_id ?? null),
                'service_name' => Cast::string($row->service_name ?? null),
                'environment_public_id' => Cast::string($row->environment_public_id ?? null),
                'environment_name' => Cast::string($row->environment_name ?? null),
                'project_public_id' => Cast::string($row->project_public_id ?? null),
                'project_name' => Cast::string($row->project_name ?? null),
                'color_slot' => Cast::id($row->color_slot ?? null),
            ])
            ->all();

        return array_values($rows);
    }
}
