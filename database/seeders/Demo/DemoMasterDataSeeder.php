<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\CustomerProvisioningSource;
use App\Enums\ProductType;
use App\Models\CustomerProfile;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Crm\CustomerAccountProvisioningService;
use App\Services\Inventory\ProductVariantUomService;
use LogicException;

/**
 * Customers, suppliers, catalogue (20 products / 42 variants), supplier references and warehouses.
 * Every record is keyed by a stable DEMO- identifier, so a rerun updates rather than duplicates.
 */
final class DemoMasterDataSeeder extends DemoSeeder
{
    protected function seed(DemoContext $context): void
    {
        $context->at(DemoContext::PeriodStart);
        $context->as('admin');

        $this->seedWarehouses();
        $units = $this->seedUnits();
        $categories = $this->seedCategories();
        $variants = $this->seedCatalogue($units, $categories);
        $this->seedSuppliers($variants);
        $this->seedCustomers($context);
    }

    private function seedWarehouses(): void
    {
        foreach (DemoFixtures::Warehouses as $code => $warehouse) {
            Warehouse::query()->updateOrCreate(['code' => $code], [
                'name' => $warehouse['name'],
                'address' => $warehouse['name'].', Dubai, United Arab Emirates',
                'latitude' => $warehouse['lat'],
                'longitude' => $warehouse['lng'],
                'is_active' => true,
            ]);
        }
    }

    /** @return array<string, Unit> */
    private function seedUnits(): array
    {
        return [
            'EA' => Unit::query()->updateOrCreate(['code' => 'EA'], [
                'name' => 'Each', 'name_ar' => 'قطعة', 'symbol' => 'EA', 'family' => 'count',
                'precision' => 0, 'allows_decimal' => false, 'is_active' => true,
            ]),
            'KG' => Unit::query()->updateOrCreate(['code' => 'KG'], [
                'name' => 'Kilogram', 'name_ar' => 'كيلوغرام', 'symbol' => 'KG', 'family' => 'mass',
                'precision' => 3, 'allows_decimal' => true, 'is_active' => true,
            ]),
        ];
    }

    /** @return array<string, ProductCategory> */
    private function seedCategories(): array
    {
        $categories = [];

        foreach (DemoFixtures::Categories as $key => $name) {
            $categories[$key] = ProductCategory::query()->updateOrCreate(
                ['name' => $name],
                ['name_ar' => $name, 'is_active' => true],
            );
        }

        return $categories;
    }

    /**
     * @param  array<string, Unit>  $units
     * @param  array<string, ProductCategory>  $categories
     * @return array<string, ProductVariant> keyed by SKU
     */
    private function seedCatalogue(array $units, array $categories): array
    {
        $variants = [];
        $each = $units['EA'];

        foreach (DemoFixtures::Products as $code => $definition) {
            $type = match ($definition['type']) {
                'machine' => ProductType::Machine,
                'grain' => ProductType::Grain,
                default => ProductType::ExpiryMaterial,
            };

            $product = Product::query()->updateOrCreate(
                ['name' => $definition['name']],
                [
                    'name_ar' => $definition['name'],
                    'description' => "{$code} — {$definition['name']} (demo catalogue).",
                    'category_id' => $categories[$definition['category']]->getKey(),
                    'product_type' => $type,
                    'status' => 'active',
                    'is_active' => true,
                ],
            );
            $product->addAllowedUnit($each);

            foreach ($definition['variants'] as [$suffix, $name, $cost, $price]) {
                $sku = self::sku($code, $suffix);
                $variant = ProductVariant::query()->updateOrCreate(['sku' => $sku], [
                    ...$type->trackingFlags(),
                    'product_id' => $product->getKey(),
                    'name' => "{$definition['name']}, {$name}",
                    'name_ar' => "{$definition['name']}, {$name}",
                    'unit_id' => $each->getKey(),
                    'cost_price' => $cost,
                    'base_price' => $price,
                    'min_price' => round($price * 0.8, 2),
                    'markup_percent' => round(($price - $cost) / $cost * 100, 2),
                    'net_weight' => $type === ProductType::Grain ? 0.25 : null,
                    'weight_unit_id' => $type === ProductType::Grain ? $units['KG']->getKey() : null,
                    'status' => 'active',
                    'is_active' => true,
                ]);

                app(ProductVariantUomService::class)->sync($variant, [[
                    'unit_id' => $each->getKey(),
                    'is_base' => true,
                    'is_purchase' => true,
                    'is_sale' => true,
                    'is_display' => true,
                    'factor_to_base' => '1',
                    'rounding_increment' => '1',
                    'permits_cross_family_conversion' => false,
                    'is_active' => true,
                ]]);

                $variants[$sku] = $variant;
            }
        }

        return $variants;
    }

    /** @param  array<string, ProductVariant>  $variants */
    private function seedSuppliers(array $variants): void
    {
        $suppliers = [];

        foreach (DemoFixtures::Suppliers as $code => $supplier) {
            $number = mb_substr($code, -3);
            $suppliers[$code] = Supplier::query()->updateOrCreate(['code' => $code], [
                'name' => $supplier['name'],
                'email' => "orders.sup{$number}@supplier.test",
                'phone' => "+97145500{$number}",
                'address' => "{$supplier['name']} Trading Zone, {$supplier['country']}",
                'is_active' => true,
                'requires_confirmation' => $supplier['confirm'],
            ]);
        }

        foreach (DemoFixtures::Products as $productCode => $definition) {
            foreach ($definition['suppliers'] as $rank => $supplierCode) {
                $supplier = $suppliers[$supplierCode];

                foreach ($definition['variants'] as [$suffix, , $cost]) {
                    $sku = self::sku($productCode, $suffix);
                    $variant = $variants[$sku];

                    SupplierProductReference::query()->updateOrCreate(
                        ['supplier_id' => $supplier->getKey(), 'product_variant_id' => $variant->getKey()],
                        [
                            'supplier_name' => $supplier->name,
                            'supplier_item_number' => "{$supplierCode}-{$suffix}-{$productCode}",
                            'country_code' => DemoFixtures::Suppliers[$supplierCode]['country'],
                            'manufacturer' => $supplier->name,
                            'purchase_cost' => $rank === 0 ? $cost : round($cost * 1.03, 2),
                            'currency_code' => 'AED',
                            'notes' => 'Demo purchasing reference.',
                            'availability_status' => 'active',
                            'lead_time_days' => 3 + $rank * 2,
                            'minimum_order_quantity' => 1,
                            'is_preferred' => $rank === 0,
                            'is_active' => true,
                        ],
                    );
                }
            }
        }
    }

    private function seedCustomers(DemoContext $context): void
    {
        $provisioner = app(CustomerAccountProvisioningService::class);
        $terms = PaymentTerm::query()->pluck('id', 'name');

        foreach (DemoFixtures::Customers as $code => $customer) {
            $number = mb_substr($code, -3);

            $profile = CustomerProfile::query()->where('customer_code', $code)->first();

            if (! $profile instanceof CustomerProfile) {
                $profile = $provisioner->provision(
                    [
                        'name' => $customer['name'],
                        'username' => 'demo-cust-'.$number,
                        'email' => "demo.cust{$number}@ierp.test",
                        'password' => DemoContext::Password,
                    ],
                    [
                        'customer_code' => $code,
                        'company_name' => $customer['name'],
                        'email' => "accounts.cust{$number}@clinic.test",
                        'phone' => "+97150700{$number}",
                        'address' => "Building {$number}, {$customer['city']} Healthcare District",
                        'country' => 'AE',
                        'city' => $customer['city'],
                        'latitude' => $customer['lat'],
                        'longitude' => $customer['lng'],
                        'contact_is_self' => true,
                        'is_active' => true,
                        'allow_direct_orders' => true,
                    ],
                    [],
                    CustomerProvisioningSource::Dashboard,
                );
            }

            $termId = $terms[$customer['term']] ?? throw new LogicException("Payment term [{$customer['term']}] missing.");
            $profile->forceFill(['default_payment_term_id' => $termId])->save();
        }
    }

    public static function sku(string $productCode, string $suffix): string
    {
        return "DEMO-{$productCode}-{$suffix}";
    }
}
