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
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
        });

        $names = [
            'AED' => 'UAE Dirham',
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'GBP' => 'British Pound',
            'SAR' => 'Saudi Riyal',
            'QAR' => 'Qatari Riyal',
            'KWD' => 'Kuwaiti Dinar',
            'BHD' => 'Bahraini Dinar',
            'OMR' => 'Omani Rial',
            'JOD' => 'Jordanian Dinar',
            'TRY' => 'Turkish Lira',
        ];

        $codes = array_keys($names);

        foreach ([
            ['supplier_product_references', 'currency_code'],
            ['ticket_payment_links', 'currency'],
            ['purchase_settings', 'approval_threshold_currency'],
            ['purchase_orders', 'currency_code'],
            ['payments', 'currency'],
            ['sales_opportunities', 'currency'],
        ] as [$table, $column]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }
            foreach (DB::table($table)->whereNotNull($column)->distinct()->pluck($column) as $stored) {
                if (is_string($stored) && mb_trim($stored) !== '') {
                    $codes[] = mb_strtoupper(mb_trim($stored));
                }
            }
        }

        foreach (array_values(array_unique($codes)) as $code) {
            if (mb_strlen($code) !== 3) {
                continue;
            }

            DB::table('currencies')->insert([
                'code' => $code,
                'name' => $names[$code] ?? $code,
                'is_active' => true,
                'is_default' => $code === 'AED',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
