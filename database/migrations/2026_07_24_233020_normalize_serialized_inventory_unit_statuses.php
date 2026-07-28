<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('serialized_inventory_units')
            ->whereNotIn('status', [
                'pending',
                'available',
                'in_transit',
                'adjusted_out',
                'damaged',
                'disposed',
                'unknown',
            ])
            ->update(['status' => 'unknown']);
    }

    public function down(): void {}
};
