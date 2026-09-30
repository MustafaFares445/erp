<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string INDEX = 'supplier_reference_active_variant_unique';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX
            .' ON supplier_product_references (supplier_id, product_variant_id)'
            .' WHERE is_active = 1 AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX
            .' ON supplier_product_references (supplier_id, product_variant_id)'
        );
    }
};
