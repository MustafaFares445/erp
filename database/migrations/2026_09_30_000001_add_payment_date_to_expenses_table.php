<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->date('payment_date')->nullable()->after('paid_at')->index();
        });

        DB::table('expenses')
            ->where('status', 'paid')
            ->whereNull('payment_date')
            ->orderBy('id')
            ->chunkById(100, function ($expenses): void {
                foreach ($expenses as $expense) {
                    $date = DB::table('journal_entries')
                        ->where('source_type', 'App\\Models\\Expense')
                        ->where('source_id', $expense->id)
                        ->where('status', 'posted')
                        ->when(
                            $expense->journal_entry_id !== null,
                            fn ($query) => $query->where('id', '!=', $expense->journal_entry_id),
                        )
                        ->orderByDesc('entry_date')
                        ->value('entry_date');

                    if ($date !== null) {
                        DB::table('expenses')->where('id', $expense->id)->update(['payment_date' => $date]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropIndex(['payment_date']);
            $table->dropColumn('payment_date');
        });
    }
};
