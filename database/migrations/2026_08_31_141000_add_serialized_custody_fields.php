<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serialized_inventory_units', function (Blueprint $table): void {
            $table->string('custody_type', 30)->default('unknown')->after('status')->index();
            $table->string('custody_reference_type', 100)->nullable()->after('custody_type');
            $table->unsignedBigInteger('custody_reference_id')->nullable()->after('custody_reference_type');
            $table->foreignId('inventory_lot_id')->nullable()->after('inventory_receipt_item_id')->constrained('inventory_lots')->nullOnDelete();
            $table->index(['custody_reference_type', 'custody_reference_id'], 'serialized_inventory_units_custody_reference_index');
        });

        DB::table('serialized_inventory_units')->orderBy('id')->get()->each(function (object $unit): void {
            $status = $unit->status;

            if (! is_string($status)) {
                throw new RuntimeException('Serialized inventory unit backfill encountered a non-string status.');
            }

            $custody = match ($status) {
                'available', 'damaged' => $unit->warehouse_id === null ? 'unknown' : 'warehouse',
                'in_transit' => 'in_transit',
                'delivered' => 'customer',
                'disposed' => 'disposed',
                default => 'unknown',
            };

            DB::table('serialized_inventory_units')->where('id', $unit->id)->update([
                'custody_type' => $custody,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('serialized_inventory_units', function (Blueprint $table): void {
            $table->dropIndex('serialized_inventory_units_custody_reference_index');
            $table->dropConstrainedForeignId('inventory_lot_id');
            $table->dropColumn(['custody_type', 'custody_reference_type', 'custody_reference_id']);
        });
    }
};
