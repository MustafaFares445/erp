<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InventoryAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['type', 'subject_type', 'subject_id', 'message', 'resolved_at'])]
final class InventoryAlert extends Model
{
    /** @use HasFactory<InventoryAlertFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
