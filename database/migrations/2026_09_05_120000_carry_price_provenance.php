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
        $allowedSources = [
            'customer_specific_tier',
            'product_scoped_tier',
            'general_tier',
            'base',
            'manual_override',
        ];

        $invalidSources = DB::table('quotation_lines')
            ->whereNotNull('resolved_price_source')
            ->whereNotIn('resolved_price_source', $allowedSources)
            ->distinct()
            ->pluck('resolved_price_source');

        if ($invalidSources->isNotEmpty()) {
            throw new \RuntimeException(sprintf(
                'Cannot type sales price provenance: unsupported quotation sources [%s].',
                $invalidSources->implode(', '),
            ));
        }

        Schema::table('quotation_lines', function (Blueprint $table): void {
            $table->foreignId('resolved_price_tier_id')->nullable()->constrained('pricing_tiers')->nullOnDelete();
            $table->foreignId('price_floor_override_id')->nullable()->constrained('price_floor_overrides')->nullOnDelete();
            $table->unsignedBigInteger('list_price_minor')->nullable();
            $table->unsignedBigInteger('floor_price_minor')->nullable();
        });

        Schema::table('order_lines', function (Blueprint $table): void {
            $table->string('resolved_price_source', 40)->nullable();
            $table->foreignId('resolved_price_tier_id')->nullable()->constrained('pricing_tiers')->nullOnDelete();
            $table->foreignId('price_floor_override_id')->nullable()->constrained('price_floor_overrides')->nullOnDelete();
            $table->unsignedBigInteger('list_price_minor')->nullable();
            $table->unsignedBigInteger('floor_price_minor')->nullable();
        });

        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->string('resolved_price_source', 40)->nullable();
            $table->foreignId('resolved_price_tier_id')->nullable()->constrained('pricing_tiers')->nullOnDelete();
            $table->foreignId('price_floor_override_id')->nullable()->constrained('price_floor_overrides')->nullOnDelete();
            $table->unsignedBigInteger('list_price_minor')->nullable();
            $table->unsignedBigInteger('floor_price_minor')->nullable();
        });

        $this->backfillUnambiguousQuotationSources();
        $this->copyOrderProvenanceToInvoices();
    }

    private function backfillUnambiguousQuotationSources(): void
    {
        DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->whereNotNull('orders.quotation_id')
            ->select([
                'order_lines.id',
                'orders.quotation_id',
                'order_lines.product_variant_id',
                'order_lines.unit_id',
                'order_lines.unit_price',
            ])
            ->orderBy('order_lines.id')
            ->each(function (object $orderLine): void {
                $query = DB::table('quotation_lines')
                    ->where('quotation_id', $orderLine->quotation_id)
                    ->where('product_variant_id', $orderLine->product_variant_id);

                if ($orderLine->unit_id !== null) {
                    $query->where('unit_id', $orderLine->unit_id);
                }

                if ($orderLine->unit_price !== null) {
                    $query->where('unit_price', $orderLine->unit_price);
                }

                $matches = $query->limit(2)->get([
                    'resolved_price_source',
                    'resolved_price_tier_id',
                    'price_floor_override_id',
                    'list_price_minor',
                    'floor_price_minor',
                ]);

                if ($matches->count() !== 1) {
                    return;
                }

                $match = $matches->first();
                DB::table('order_lines')->where('id', $orderLine->id)->update([
                    'resolved_price_source' => $match->resolved_price_source,
                    'resolved_price_tier_id' => $match->resolved_price_tier_id,
                    'price_floor_override_id' => $match->price_floor_override_id,
                    'list_price_minor' => $match->list_price_minor,
                    'floor_price_minor' => $match->floor_price_minor,
                ]);
            });
    }

    private function copyOrderProvenanceToInvoices(): void
    {
        DB::table('invoice_lines')
            ->whereNotNull('order_line_id')
            ->orderBy('id')
            ->each(function (object $invoiceLine): void {
                $source = DB::table('order_lines')
                    ->where('id', $invoiceLine->order_line_id)
                    ->first([
                        'resolved_price_source',
                        'resolved_price_tier_id',
                        'price_floor_override_id',
                        'list_price_minor',
                        'floor_price_minor',
                    ]);

                if ($source === null) {
                    return;
                }

                DB::table('invoice_lines')->where('id', $invoiceLine->id)->update([
                    'resolved_price_source' => $source->resolved_price_source,
                    'resolved_price_tier_id' => $source->resolved_price_tier_id,
                    'price_floor_override_id' => $source->price_floor_override_id,
                    'list_price_minor' => $source->list_price_minor,
                    'floor_price_minor' => $source->floor_price_minor,
                ]);
            });
    }

    public function down(): void
    {
        foreach (['invoice_lines', 'order_lines', 'quotation_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('resolved_price_tier_id');
                $table->dropConstrainedForeignId('price_floor_override_id');
                $table->dropColumn(['list_price_minor', 'floor_price_minor']);
            });
        }

        Schema::table('invoice_lines', fn (Blueprint $table) => $table->dropColumn('resolved_price_source'));
        Schema::table('order_lines', fn (Blueprint $table) => $table->dropColumn('resolved_price_source'));
    }
};
