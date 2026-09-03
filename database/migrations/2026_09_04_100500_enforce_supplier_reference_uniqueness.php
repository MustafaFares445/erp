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
        $bills = DB::table('bills')
            ->select(['id', 'bill_number', 'supplier_id', 'supplier_reference'])
            ->orderBy('id')
            ->get();

        /** @var array<string, list<string>> $references */
        $references = [];

        foreach ($bills as $bill) {
            $billNumber = is_string($bill->bill_number)
                ? $bill->bill_number
                : 'BILL-'.$bill->id;
            $reference = is_string($bill->supplier_reference)
                ? trim($bill->supplier_reference)
                : '';

            if ($reference === '') {
                $reference = 'LEGACY-'.$billNumber;
            }

            $key = sprintf('%d|%s', (int) $bill->supplier_id, $reference);
            $references[$key] ??= [];
            $references[$key][] = $billNumber;
        }

        $conflicts = array_filter(
            $references,
            static fn (array $billNumbers): bool => count($billNumbers) > 1,
        );

        if ($conflicts !== []) {
            $details = array_map(
                static fn (array $billNumbers): string => implode(', ', $billNumbers),
                array_values($conflicts),
            );

            throw new \RuntimeException(
                'Cannot enforce supplier invoice reference uniqueness. Conflicting bills: '
                .implode(' | ', $details),
            );
        }

        Schema::table('bills', function (Blueprint $table): void {
            $table->timestamp('supplier_reference_backfilled_at')
                ->nullable()
                ->after('supplier_reference');
        });

        DB::table('bills')
            ->select(['id', 'bill_number', 'supplier_reference'])
            ->orderBy('id')
            ->chunkById(250, function ($bills): void {
                foreach ($bills as $bill) {
                    $reference = is_string($bill->supplier_reference)
                        ? trim($bill->supplier_reference)
                        : '';

                    if ($reference !== '') {
                        continue;
                    }

                    $billNumber = is_string($bill->bill_number)
                        ? $bill->bill_number
                        : 'BILL-'.$bill->id;

                    DB::table('bills')
                        ->where('id', $bill->id)
                        ->update([
                            'supplier_reference' => 'LEGACY-'.$billNumber,
                            'supplier_reference_backfilled_at' => now(),
                        ]);
                }
            });

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropIndex('bills_supplier_id_supplier_reference_index');
            $table->string('supplier_reference', 100)->nullable(false)->change();
            $table->unique(
                ['supplier_id', 'supplier_reference'],
                'bills_supplier_reference_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->dropUnique('bills_supplier_reference_unique');
            $table->string('supplier_reference', 100)->nullable()->change();
        });

        DB::table('bills')
            ->whereNotNull('supplier_reference_backfilled_at')
            ->update(['supplier_reference' => null]);

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropColumn('supplier_reference_backfilled_at');
            $table->index(
                ['supplier_id', 'supplier_reference'],
                'bills_supplier_id_supplier_reference_index',
            );
        });
    }
};
