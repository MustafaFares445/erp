<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weight dimension for bulk goods sold by weight (ProductType::Grain).
 *
 * Nullable throughout so existing variants stay valid: weight is derived reporting data,
 * never an input to a stock balance, so a grain variant without one still receives, ships
 * and transfers correctly — it simply reports no weight until an administrator supplies it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->decimal('net_weight', 15, 3)->nullable()->after('track_expiry');
            $table->foreignId('weight_unit_id')->nullable()->after('net_weight')->constrained('units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('weight_unit_id');
            $table->dropColumn('net_weight');
        });
    }
};
