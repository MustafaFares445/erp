<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('stock_transfers')
            ->where('status', 'confirmed')
            ->update([
                'status' => 'received',
                'received_at' => DB::raw('COALESCE(received_at, updated_at)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('stock_transfers')
            ->where('status', 'received')
            ->whereNotNull('received_at')
            ->update(['status' => 'confirmed']);
    }
};
