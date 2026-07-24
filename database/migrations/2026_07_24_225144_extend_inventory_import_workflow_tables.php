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
        Schema::table('inventory_import_runs', function (Blueprint $table): void {
            $table->unsignedInteger('created_rows')->default(0)->after('failed_rows');
            $table->unsignedInteger('updated_rows')->default(0)->after('created_rows');
            $table->unsignedInteger('applied_rows')->default(0)->after('updated_rows');
            $table->unsignedInteger('rejected_rows')->default(0)->after('applied_rows');
            $table->text('failure_message')->nullable()->after('rejected_rows');
            $table->foreignId('confirmed_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('applying_at')->nullable()->after('confirmed_by');
            $table->string('result_path')->nullable()->after('confirmed_at');
            $table->string('summary_path')->nullable()->after('result_path');
        });

        Schema::table('inventory_import_items', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('row_number');
            $table->text('runtime_error')->nullable()->after('errors');
            $table->json('result')->nullable()->after('runtime_error');
            $table->string('operation', 40)->nullable()->after('status');
            $table->unique('idempotency_key', 'inventory_import_item_idempotency_unique');
        });

        DB::table('inventory_import_runs')
            ->where('status', 'invalid')
            ->where('valid_rows', '>', 0)
            ->update(['status' => 'ready_with_errors']);

        DB::table('inventory_import_runs')
            ->where('status', 'uploaded')
            ->update(['status' => 'queued']);

        DB::table('inventory_import_items')
            ->where('status', 'pending')
            ->update([
                'status' => 'invalid',
                'errors' => json_encode(['row' => ['legacy_unvalidated']]),
            ]);

        Schema::table('inventory_import_runs', function (Blueprint $table): void {
            $table->string('status', 30)->default('queued')->change();
        });

        DB::table('inventory_import_items')
            ->select(['id', 'inventory_import_run_id', 'row_number'])
            ->orderBy('id')
            ->eachById(function (object $item): void {
                $runId = $item->inventory_import_run_id;
                $rowNumber = $item->row_number;

                if (! is_int($runId) || ! is_int($rowNumber)) {
                    throw new LogicException('Import run and row identifiers must be integers.');
                }

                DB::table('inventory_import_items')
                    ->where('id', $item->id)
                    ->update([
                        'idempotency_key' => hash('sha256', "{$runId}:{$rowNumber}"),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('inventory_import_runs', function (Blueprint $table): void {
            $table->string('status', 30)->default('uploaded')->change();
        });

        Schema::table('inventory_import_items', function (Blueprint $table): void {
            $table->dropUnique('inventory_import_item_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'runtime_error', 'result', 'operation']);
        });

        Schema::table('inventory_import_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn([
                'created_rows',
                'updated_rows',
                'applied_rows',
                'rejected_rows',
                'failure_message',
                'applying_at',
                'result_path',
                'summary_path',
            ]);
        });
    }
};
