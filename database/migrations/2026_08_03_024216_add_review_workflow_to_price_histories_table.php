<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_histories', function (Blueprint $table): void {
            $table->string('status')->default('approved')->after('markup_percent');
            $table->foreignId('reviewed_by')->nullable()->after('changed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('price_histories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'reviewed_at']);
        });
    }
};
