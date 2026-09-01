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
        Schema::table('units', function (Blueprint $table): void {
            $table->string('code', 50)->nullable()->unique()->after('id');
            $table->string('family', 50)->default('unspecified')->index()->after('symbol');
            $table->unsignedTinyInteger('precision')->default(3)->after('allows_decimal');
        });

        DB::table('units')
            ->where('allows_decimal', false)
            ->update(['precision' => 0]);

        Schema::create('product_variant_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->boolean('is_base')->default(false);
            $table->boolean('is_purchase')->default(false);
            $table->boolean('is_sale')->default(false);
            $table->boolean('is_display')->default(false);
            $table->decimal('factor_to_base', 20, 6);
            $table->decimal('rounding_increment', 20, 6);
            $table->boolean('permits_cross_family_conversion')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['product_variant_id', 'unit_id']);
            $table->index(['product_variant_id', 'is_base']);
        });

        $timestamp = now();

        DB::table('product_variants')
            ->join('units', 'units.id', '=', 'product_variants.unit_id')
            ->select('product_variants.id', 'product_variants.unit_id', 'units.precision')
            ->orderBy('product_variants.id')
            ->each(function (object $variant) use ($timestamp): void {
                $precision = $variant->precision;

                if (! is_numeric($precision)) {
                    throw new RuntimeException('Unit precision backfill encountered a non-numeric value.');
                }

                DB::table('product_variant_units')->insert([
                    'product_variant_id' => $variant->id,
                    'unit_id' => $variant->unit_id,
                    'is_base' => true,
                    'is_purchase' => true,
                    'is_sale' => true,
                    'is_display' => true,
                    'factor_to_base' => '1.000000',
                    'rounding_increment' => (int) $precision === 0 ? '1.000000' : '0.001000',
                    'permits_cross_family_conversion' => false,
                    'is_active' => true,
                    'effective_from' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_units');

        Schema::table('units', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropIndex(['family']);
            $table->dropColumn(['code', 'family', 'precision']);
        });
    }
};
