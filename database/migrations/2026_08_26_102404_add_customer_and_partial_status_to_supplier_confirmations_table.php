<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_confirmations', function (Blueprint $table): void {
            $table->string('confirmable_type')->nullable()->change();
            $table->unsignedBigInteger('confirmable_id')->nullable()->change();
            $table->foreignId('customer_id')->nullable()->after('supplier_id')->constrained('customer_profiles')->nullOnDelete();
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_confirmations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
            $table->string('confirmable_type')->nullable(false)->change();
            $table->unsignedBigInteger('confirmable_id')->nullable(false)->change();
        });
    }
};
