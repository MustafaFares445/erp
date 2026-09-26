<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_settings', function (Blueprint $table): void {
            $table->decimal('max_price_floor_override_percent', 5, 2)->nullable()->after('default_markup_percent');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_settings', function (Blueprint $table): void {
            $table->dropColumn('max_price_floor_override_percent');
        });
    }
};
