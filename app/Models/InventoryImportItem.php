<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InventoryImportItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['inventory_import_run_id', 'row_number', 'payload', 'errors', 'status', 'applied_at'])]
final class InventoryImportItem extends Model
{
    /** @use HasFactory<InventoryImportItemFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return ['payload' => 'array', 'errors' => 'array', 'applied_at' => 'datetime'];
    }

    /** @return BelongsTo<InventoryImportRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(InventoryImportRun::class, 'inventory_import_run_id');
    }
}
