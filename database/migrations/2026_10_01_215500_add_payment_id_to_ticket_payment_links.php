<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_payment_links', function (Blueprint $table): void {
            $table->foreignId('payment_id')
                ->nullable()
                ->unique()
                ->after('ticket_id')
                ->constrained('payments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_payment_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_id');
        });
    }
};
