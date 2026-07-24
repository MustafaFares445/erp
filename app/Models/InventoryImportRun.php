<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InventoryImportRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['file_path', 'status', 'total_rows', 'valid_rows', 'failed_rows', 'created_by', 'confirmed_at'])]
final class InventoryImportRun extends Model
{
    /** @use HasFactory<InventoryImportRunFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<InventoryImportItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryImportItem::class);
    }
}
