<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_operations', function (Blueprint $table): void {
            $table->string('tracking_number', 100)->nullable()->unique()->after('operation_number');
            $table->string('delivery_type', 20)->nullable()->after('customer_id');
        });

        DB::table('inventory_operations')
            ->where('operation_type', 'delivery')
            ->where(function (Builder $query): void {
                $query->whereNull('tracking_number')->orWhereNull('delivery_type');
            })
            ->orderBy('id')
            ->get(['id', 'tracking_number', 'delivery_type'])
            ->each(static function (object $operation): void {
                /** @var object{id: int, tracking_number: string|null, delivery_type: string|null} $operation */
                $attributes = [
                    'tracking_number' => $operation->tracking_number
                        ?? 'TRK-LEGACY-'.mb_str_pad((string) $operation->id, 6, '0', STR_PAD_LEFT),
                    'delivery_type' => $operation->delivery_type ?? 'inner',
                ];

                DB::table('inventory_operations')
                    ->where('id', $operation->id)
                    ->update($attributes);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_operations', function (Blueprint $table): void {
            $table->dropUnique(['tracking_number']);
            $table->dropColumn(['tracking_number', 'delivery_type']);
        });
    }
};
