<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table): void {
            $table->decimal('damaged_quantity', 15, 3)
                ->default(0)
                ->after('reserved_quantity');
        });

        Schema::table('inventory_alerts', function (Blueprint $table): void {
            $table->string('severity', 20)
                ->default('warning')
                ->after('message')
                ->index();
            $table->json('context')
                ->nullable()
                ->after('severity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_alerts', function (Blueprint $table): void {
            $table->dropColumn(['severity', 'context']);
        });

        Schema::table('inventory_stocks', function (Blueprint $table): void {
            $table->dropColumn('damaged_quantity');
        });
    }
};
