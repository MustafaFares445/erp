<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};
