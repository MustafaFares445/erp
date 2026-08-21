<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryAlertSeverity;
use App\Enums\InventoryAlertType;
use Database\Factories\InventoryAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['type', 'subject_type', 'subject_id', 'message', 'severity', 'context', 'resolved_at'])]
final class InventoryAlert extends Model
{
    /** @use HasFactory<InventoryAlertFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return [
            'type' => InventoryAlertType::class,
            'severity' => InventoryAlertSeverity::class,
            'context' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isActive(): bool
    {
        return $this->resolved_at === null;
    }
}
