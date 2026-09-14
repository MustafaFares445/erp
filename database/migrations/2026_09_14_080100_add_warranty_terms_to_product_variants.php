<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unsignedInteger('warranty_duration_value')->nullable()->after('markup_percent');
            $table->string('warranty_duration_unit')->nullable()->after('warranty_duration_value');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn(['warranty_duration_value', 'warranty_duration_unit']);
        });
    }
};
