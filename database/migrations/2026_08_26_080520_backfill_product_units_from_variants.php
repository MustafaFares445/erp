<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $variantUnits = DB::table('product_variants')
            ->whereNotNull('unit_id')
            ->select('product_id', 'unit_id')
            ->distinct()
            ->orderBy('product_id')
            ->orderBy('unit_id')
            ->get();

        $rows = [];

        foreach ($variantUnits->groupBy('product_id') as $productId => $units) {
            $defaultUnitId = null;

            foreach ($units as $unit) {
                $defaultUnitId ??= $unit->unit_id;
                $rows[] = [
                    'product_id' => $productId,
                    'unit_id' => $unit->unit_id,
                    'is_default' => $unit->unit_id === $defaultUnitId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($rows !== []) {
            DB::table('product_units')->insert($rows);
        }
    }

    public function down(): void {}
};
