<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InventoryExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'filters', 'file_path', 'status', 'failure_reason', 'created_by', 'completed_at'])]
final class InventoryExport extends Model
{
    /** @use HasFactory<InventoryExportFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return ['filters' => 'array', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
