<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\RecordFavorite;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Lets each user star records of this model. Favorites are private to the
 * user who set them and drive the "Starred" view on list pages.
 */
trait HasFavorites
{
    /** @return MorphMany<RecordFavorite, $this> */
    public function favorites(): MorphMany
    {
        return $this->morphMany(RecordFavorite::class, 'favoritable');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFavoritedBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('favorites', static fn (Builder $favorite): Builder => $favorite->where('user_id', $user->getKey()));
    }

    public function isFavoritedBy(User $user): bool
    {
        return $this->favorites()->where('user_id', $user->getKey())->exists();
    }

    /** Stars the record for the user, or removes the star if it is already set. */
    public function toggleFavoriteFor(User $user): bool
    {
        $deleted = $this->favorites()->where('user_id', $user->getKey())->delete();

        if ($deleted > 0) {
            return false;
        }

        $this->favorites()->create(['user_id' => $user->getKey()]);

        return true;
    }
}
