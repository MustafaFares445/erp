<?php

declare(strict_types=1);

namespace App\Services\Collaboration;

use App\Models\CollaborationEntry;
use App\Models\CollaborationFollower;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CollaborationService
{
    /** @param array{type:string,body:string,assignee_id?:int|null,parent_id?:int|null,due_at?:string|null,visibility?:string|null,metadata?:array<string,mixed>|null} $data */
    public function create(User $actor, Model $subject, array $data): CollaborationEntry
    {
        $type = $data['type'];
        if (! in_array($type, ['comment', 'note', 'activity'], true)) {
            throw new InvalidArgumentException('Unsupported collaboration entry type.');
        }

        $body = mb_trim($data['body']);
        if ($body === '') {
            throw new InvalidArgumentException('Collaboration body is required.');
        }

        return DB::transaction(function () use ($actor, $subject, $data, $type, $body): CollaborationEntry {
            /** @var CollaborationEntry $entry */
            $entry = $subject->morphMany(CollaborationEntry::class, 'subject')->create([
                'type' => $type,
                'author_id' => $actor->getKey(),
                'assignee_id' => $data['assignee_id'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
                'body' => $body,
                'due_at' => $type === 'activity' ? ($data['due_at'] ?? null) : null,
                'visibility' => $data['visibility'] ?? 'internal',
                'metadata' => $data['metadata'] ?? null,
            ]);

            $this->follow($actor, $subject);

            return $entry;
        });
    }

    public function complete(User $actor, CollaborationEntry $entry): CollaborationEntry
    {
        if ($entry->type !== 'activity') {
            throw new InvalidArgumentException('Only collaboration activities can be completed.');
        }
        $entry->forceFill([
            'completed_at' => now(),
            'metadata' => array_merge($entry->metadata ?? [], ['completed_by' => $actor->getKey()]),
        ])->save();

        return $entry->refresh();
    }

    public function follow(User $actor, Model $subject): CollaborationFollower
    {
        return CollaborationFollower::query()->firstOrCreate([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'user_id' => $actor->getKey(),
        ]);
    }

    public function unfollow(User $actor, Model $subject): void
    {
        CollaborationFollower::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('user_id', $actor->getKey())
            ->delete();
    }
}
