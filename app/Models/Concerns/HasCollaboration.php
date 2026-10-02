<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\CollaborationEntry;
use App\Models\CollaborationFollower;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasCollaboration
{
    /** @return MorphMany<CollaborationEntry, $this> */
    public function collaborationEntries(): MorphMany
    {
        return $this->morphMany(CollaborationEntry::class, 'subject')->latest();
    }

    /** @return MorphMany<CollaborationFollower, $this> */
    public function collaborationFollowers(): MorphMany
    {
        return $this->morphMany(CollaborationFollower::class, 'subject');
    }
}
