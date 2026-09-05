<?php

declare(strict_types=1);

use App\Enums\CreditNoteReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->string('reason_category', 30)->default(CreditNoteReason::Other->value)->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->dropColumn('reason_category');
        });
    }
};
