<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryImportRunStatus;
use Database\Factories\InventoryImportRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'file_path',
    'status',
    'total_rows',
    'valid_rows',
    'failed_rows',
    'created_rows',
    'updated_rows',
    'applied_rows',
    'rejected_rows',
    'failure_message',
    'created_by',
    'confirmed_by',
    'applying_at',
    'confirmed_at',
    'result_path',
    'summary_path',
])]
final class InventoryImportRun extends Model
{
    /** @use HasFactory<InventoryImportRunFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return [
            'status' => InventoryImportRunStatus::class,
            'applying_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return HasMany<InventoryImportItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryImportItem::class);
    }
}
