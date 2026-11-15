<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\ProtocolDocument;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * POST /daemon/v1/tasks/{id}/complete (protocol/schema/v1/task-completion.json).
 */
final class TaskCompletion extends Data
{
    /**
     * @param  'succeeded'|'failed'|'cancelled'|'released'  $outcome  released: launchroomd stopped before the task finished and hands it back (docs/03 §7)
     */
    public function __construct(
        #[In([ProtocolDocument::API_VERSION])]
        public string $apiVersion,
        #[In(['TaskCompletion'])]
        public string $kind,
        #[Min(1)]
        public int $attempt,
        #[In(['succeeded', 'failed', 'cancelled', 'released'])]
        public string $outcome,
        #[Max(4096)]
        public string|Optional $error = new Optional,
        /** The result fields, flat on the wire (Go embeds them the same way). */
        public TaskResult $results = new TaskResult,
        /**
         * inventory tasks that succeeded (docs/03 §13.1): read by hand with
         * InventoryResult::fromWire() (up to 20,000 components).
         *
         * @var array<string, mixed>|null|Optional
         */
        public array|null|Optional $inventory = new Optional,
        /**
         * inspect_container tasks that succeeded (docs/03 §13.2): read with
         * ContainerInspectionResult::fromWire().
         *
         * @var array<string, mixed>|null|Optional
         */
        public array|null|Optional $containerInspection = new Optional,
    ) {}

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        $fields = array_flip(TaskResult::fields());
        $results = array_intersect_key($properties, $fields);

        return [...array_diff_key($properties, $fields), ...($results === [] ? [] : ['results' => $results])];
    }

    /**
     * The wire shape: the results flat beside the envelope.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        $document = parent::toArray();
        $results = $document['results'] ?? [];
        unset($document['results']);

        return [...$document, ...(is_array($results) ? $results : [])];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'inventory' => ['sometimes', 'nullable', 'array'],
            'containerInspection' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
