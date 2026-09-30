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

        $expenses = DB::table('expenses')
            ->where('status', 'paid')
            ->whereNull('payment_date')
            ->orderBy('id')
            ->get(['id', 'journal_entry_id']);

        foreach ($expenses as $expense) {
            $expenseId = data_get($expense, 'id');
            if (! is_numeric($expenseId)) {
                continue;
            }

            $query = DB::table('journal_entries')
                ->where('source_type', 'App\\Models\\Expense')
                ->where('source_id', (int) $expenseId)
                ->where('status', 'posted');

            $approvalEntryId = data_get($expense, 'journal_entry_id');
            if (is_numeric($approvalEntryId)) {
                $query->where('id', '!=', (int) $approvalEntryId);
            }

            $date = $query->orderByDesc('entry_date')->value('entry_date');

            if (is_string($date)) {
                DB::table('expenses')->where('id', (int) $expenseId)->update(['payment_date' => $date]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropIndex(['payment_date']);
            $table->dropColumn('payment_date');
        });
    }
};
