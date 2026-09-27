<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->boolean('supplier_confirmation_required')
                ->nullable()
                ->after('status')
                ->comment('Immutable supplier-confirmation policy snapshot taken when the PO is accepted.');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn('supplier_confirmation_required');
        });
    }
};
