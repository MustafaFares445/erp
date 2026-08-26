<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('credit_note_lines', function (Blueprint $table): void {
            $table->foreign('invoice_line_id')
                ->references('id')
                ->on('invoice_lines')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_note_lines', function (Blueprint $table): void {
            $table->dropForeign(['invoice_line_id']);
        });
    }
};
