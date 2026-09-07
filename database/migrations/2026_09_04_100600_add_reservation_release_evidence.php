<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->foreignId('released_by')
                ->nullable()
                ->after('released_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('release_reason', 255)
                ->nullable()
                ->after('released_by');
            $table->index(
                ['status', 'expires_at'],
                'inventory_reservations_status_expires_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropIndex('inventory_reservations_status_expires_index');
            $table->dropConstrainedForeignId('released_by');
            $table->dropColumn('release_reason');
        });
    }
};
