<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-2.14 (GAP-BW-07). `(status, expires_at)` is the expiry sweep's covering
 * index — it runs daily against a table that grows with every sales
 * document. `requoted_from_id` links a re-quote created from an expired
 * quotation back to the original it superseded (F-18).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->index(['status', 'expires_at']);
            $table->foreignId('requoted_from_id')->nullable()->after('converted_order_id')
                ->constrained('quotations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requoted_from_id');
            $table->dropIndex(['status', 'expires_at']);
        });
    }
};
