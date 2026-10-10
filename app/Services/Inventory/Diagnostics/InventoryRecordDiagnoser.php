<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use Illuminate\Database\Eloquent\Model;

/**
 * Diagnoses one kind of Inventory record for its View page.
 *
 * Implementations are strictly read-only: they may query, never write, post, reserve or
 * change workflow state. Every finding must be backed by stored data.
 */
interface InventoryRecordDiagnoser
{
    public function supports(Model $record): bool;

    public function diagnose(Model $record): InventoryDiagnosis;
}
