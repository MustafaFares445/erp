<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('customer_profiles')
            ->orderBy('id')
            ->eachById(static function (object $customer): void {
                DB::table('customer_delivery_addresses')->updateOrInsert(
                    [
                        'customer_profile_id' => $customer->id,
                        'label' => 'Legacy profile address',
                    ],
                    [
                        'address' => $customer->address ?? '',
                        'country' => $customer->country,
                        'city' => $customer->city,
                        'latitude' => $customer->latitude,
                        'longitude' => $customer->longitude,
                        'contact_name' => $customer->contact_name,
                        'contact_phone' => $customer->contact_phone,
                        'is_active' => $customer->is_active,
                        'is_default' => true,
                        'created_at' => $customer->created_at ?? now(),
                        'updated_at' => $customer->updated_at ?? now(),
                    ],
                );
            });
    }
};
