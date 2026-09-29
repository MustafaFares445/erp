<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('supplier_product_references')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(200, function ($references): void {
                foreach ($references as $reference) {
                    $key = [
                        'supplier_id' => $reference->supplier_id,
                        'product_variant_id' => $reference->product_variant_id,
                    ];
                    $existing = DB::table('supplier_product_supports')->where($key)->first();

                    if ($existing !== null) {
                        DB::table('supplier_product_supports')->where('id', $existing->id)->update([
                            'product_id' => null,
                            'is_active' => true,
                            'deleted_at' => null,
                            'updated_at' => now(),
                        ]);

                        continue;
                    }

                    DB::table('supplier_product_supports')->insert([
                        ...$key,
                        'product_id' => null,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Capability facts are intentionally preserved on rollback.
    }
};
