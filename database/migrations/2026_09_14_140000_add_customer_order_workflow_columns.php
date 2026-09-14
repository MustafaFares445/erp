<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('confirmed_at')->nullable()->after('status');
            $table->timestamp('released_at')->nullable()->after('confirmed_at');
            $table->timestamp('closed_at')->nullable()->after('released_at');
            $table->timestamp('cancelled_at')->nullable()->after('closed_at');
        });

        Schema::table('order_lines', function (Blueprint $table): void {
            $table->decimal('short_closed_base_quantity', 20, 6)->default(0)->after('base_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropColumn('short_closed_base_quantity');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['confirmed_at', 'released_at', 'closed_at', 'cancelled_at']);
        });
    }
};
