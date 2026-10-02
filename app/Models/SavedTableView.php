<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'page_key', 'name', 'icon', 'color', 'is_public', 'state', 'state_version'])]
final class SavedTableView extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'state' => 'array',
            'state_version' => 'integer',
        ];
    }
}
