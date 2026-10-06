<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Models\AuditLog;
use App\Models\CustomerVisit;
use App\Models\EmployeeVoiceNote;
use App\Models\PlanTask;
use App\Models\SalesOpportunity;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class VisitTimelineService
{
    /**
     * @return list<array{occurred_at:string, action:string, actor:string, context:string}>
     */
    public function forVisit(CustomerVisit $visit): array
    {
        /** @var Collection<int, array{occurred_at:string, action:string, actor:string, context:string}> $entries */
        $entries = collect();

        AuditLog::query()
            ->with('causer')
            ->where('subject_type', $visit->getMorphClass())
            ->where('subject_id', $visit->getKey())
            ->orderBy('created_at')
            ->get()
            ->each(function (AuditLog $activity) use ($entries): void {
                $actor = data_get($activity, 'causer.name');
                $entries->push([
                    'occurred_at' => $activity->created_at?->toIso8601String() ?? '',
                    'action' => (string) $activity->description,
                    'actor' => is_string($actor) && $actor !== '' ? $actor : __('System'),
                    'context' => $this->context($activity->properties?->toArray() ?? []),
                ]);
            });

        $visit->voiceNotes()
            ->with(['transcription', 'employee.user'])
            ->get()
            ->each(function (EmployeeVoiceNote $note) use ($entries): void {
                $actor = data_get($note, 'employee.user.name');

                $entries->push([
                    'occurred_at' => $note->created_at?->toIso8601String() ?? '',
                    'action' => __('Voice note received'),
                    'actor' => is_string($actor) && $actor !== '' ? $actor : 'Employee',
                    'context' => $note->transcription !== null
                        ? __('Transcription status: :status', ['status' => $note->transcription->status->value])
                        : __('Awaiting transcription'),
                ]);
            });

        $visit->salesOpportunities()
            ->each(function (SalesOpportunity $opportunity) use ($entries): void {
                $entries->push([
                    'occurred_at' => $opportunity->created_at?->toIso8601String() ?? '',
                    'action' => __('Sales opportunity detected'),
                    'actor' => __('System / AI suggestion'),
                    'context' => (string) $opportunity->summary,
                ]);
            });

        $followUpTask = $visit->followUpTask()->first();

        if ($followUpTask instanceof PlanTask) {
            $entries->push([
                'occurred_at' => $followUpTask->created_at?->toIso8601String() ?? '',
                'action' => __('Follow-up task created'),
                'actor' => __('System'),
                'context' => (string) $followUpTask->title,
            ]);
        }

        $visit->getMedia('visit-attachments')
            ->each(function (Media $media) use ($entries): void {
                $entries->push([
                    'occurred_at' => $media->created_at?->toIso8601String() ?? '',
                    'action' => __('Attachment added'),
                    'actor' => __('Employee / manager'),
                    'context' => (string) $media->file_name,
                ]);
            });

        return array_values($entries
            ->filter(static fn (array $entry): bool => $entry['occurred_at'] !== '')
            ->sortBy('occurred_at')
            ->values()
            ->all());
    }

    /** @param array<array-key, mixed> $properties */
    private function context(array $properties): string
    {
        $from = data_get($properties, 'from');
        $to = data_get($properties, 'to');

        if (is_string($from) && is_string($to)) {
            return $from.' → '.$to;
        }

        $source = data_get($properties, 'source_channel');

        return is_string($source) ? __('Source: :source', ['source' => $source]) : '';
    }
}
