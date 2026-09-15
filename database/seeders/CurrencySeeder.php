<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

final class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'AED', 'name' => 'UAE Dirham', 'is_default' => true],
            ['code' => 'USD', 'name' => 'US Dollar', 'is_default' => false],
            ['code' => 'EUR', 'name' => 'Euro', 'is_default' => false],
            ['code' => 'GBP', 'name' => 'British Pound', 'is_default' => false],
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'is_default' => false],
            ['code' => 'QAR', 'name' => 'Qatari Riyal', 'is_default' => false],
            ['code' => 'KWD', 'name' => 'Kuwaiti Dinar', 'is_default' => false],
            ['code' => 'BHD', 'name' => 'Bahraini Dinar', 'is_default' => false],
            ['code' => 'OMR', 'name' => 'Omani Rial', 'is_default' => false],
            ['code' => 'JOD', 'name' => 'Jordanian Dinar', 'is_default' => false],
            ['code' => 'TRY', 'name' => 'Turkish Lira', 'is_default' => false],
        ] as $currency) {
            Currency::query()->updateOrCreate(
                ['code' => $currency['code']],
                [
                    'name' => $currency['name'],
                    'is_active' => true,
                    'is_default' => $currency['is_default'],
                ],
            );
        }
    }
}
