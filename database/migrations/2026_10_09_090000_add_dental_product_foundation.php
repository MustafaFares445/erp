<?php

declare(strict_types=1);

use App\Enums\ProductOperationalProfile;
use App\Enums\ProductType;
use App\Enums\TrackingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 80)->unique();
            $table->string('country_code', 2)->nullable()->index();
            $table->string('website', 500)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('brands', function (Blueprint $table): void {
            $table->foreignId('manufacturer_id')
                ->nullable()
                ->after('id')
                ->constrained('manufacturers')
                ->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('manufacturer_id')
                ->nullable()
                ->after('category_id')
                ->constrained('manufacturers')
                ->nullOnDelete();
            $table->string('operational_profile', 40)
                ->nullable()
                ->after('product_type')
                ->index();
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('manufacturer_part_number', 150)->nullable()->after('barcode')->index();
            $table->string('gtin', 32)->nullable()->after('manufacturer_part_number')->unique();
            $table->string('udi_di', 255)->nullable()->after('gtin')->unique();
            $table->string('device_identifier', 255)->nullable()->after('udi_di');
            $table->string('tracking_mode', 20)->nullable()->after('unit_id')->index();
            $table->boolean('tracks_expiration')->nullable()->after('tracking_mode');
            $table->boolean('serviceable')->default(false)->after('track_batches');
            $table->boolean('warranty_enabled')->default(false)->after('serviceable');
            $table->boolean('udi_enabled')->default(false)->after('warranty_enabled');
        });

        Schema::table('product_variant_units', function (Blueprint $table): void {
            $table->string('packaging_name', 120)->nullable()->after('unit_id');
            $table->string('barcode', 100)->nullable()->after('packaging_name');
            $table->index(['product_variant_id', 'is_purchase']);
            $table->index(['product_variant_id', 'is_sale']);
        });

        DB::table('products')
            ->whereNull('operational_profile')
            ->where('product_type', ProductType::Machine->value)
            ->update(['operational_profile' => ProductOperationalProfile::Serialized->value]);

        DB::table('products')
            ->whereNull('operational_profile')
            ->where('product_type', ProductType::ExpiryMaterial->value)
            ->update(['operational_profile' => ProductOperationalProfile::Expiring->value]);

        DB::table('products')
            ->whereNull('operational_profile')
            ->update(['operational_profile' => ProductOperationalProfile::Standard->value]);

        DB::table('product_variants')
            ->whereNull('tracking_mode')
            ->where('track_serials', true)
            ->update(['tracking_mode' => TrackingMode::Serial->value]);

        DB::table('product_variants')
            ->whereNull('tracking_mode')
            ->where('track_batches', true)
            ->update(['tracking_mode' => TrackingMode::Lot->value]);

        DB::table('product_variants')
            ->whereNull('tracking_mode')
            ->update(['tracking_mode' => TrackingMode::None->value]);

        DB::table('product_variants')
            ->whereNull('tracks_expiration')
            ->update(['tracks_expiration' => DB::raw('track_expiry')]);

        DB::table('product_variants')
            ->whereNotNull('warranty_policy_id')
            ->update(['warranty_enabled' => true]);

        DB::table('product_variants')
            ->whereNotNull('warranty_duration_value')
            ->update(['warranty_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('product_variant_units', function (Blueprint $table): void {
            $table->dropIndex(['product_variant_id', 'is_purchase']);
            $table->dropIndex(['product_variant_id', 'is_sale']);
            $table->dropColumn(['packaging_name', 'barcode']);
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique(['gtin']);
            $table->dropUnique(['udi_di']);
            $table->dropIndex(['manufacturer_part_number']);
            $table->dropIndex(['tracking_mode']);
            $table->dropColumn([
                'manufacturer_part_number',
                'gtin',
                'udi_di',
                'device_identifier',
                'tracking_mode',
                'tracks_expiration',
                'serviceable',
                'warranty_enabled',
                'udi_enabled',
            ]);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['operational_profile']);
            $table->dropConstrainedForeignId('manufacturer_id');
            $table->dropColumn('operational_profile');
        });

        Schema::table('brands', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('manufacturer_id');
        });

        Schema::dropIfExists('manufacturers');
    }
};
