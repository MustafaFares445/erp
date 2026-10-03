<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;

/**
 * A model users can star. Implemented through {@see HasFavorites}.
 */
interface Favoritable
{
    public function isFavoritedBy(User $user): bool;

    public function toggleFavoriteFor(User $user): bool;
}
