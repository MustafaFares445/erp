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

/**
 * Single shared development company's expanded synthetic master records.
 *
 * Uses unique DEMO-REAL identifiers: safe to rerun and never overwrites real companies.
 * Realistic industry terminology without claiming these are manufacturer SKUs.
 */
final class UnifiedMasterDataSeeder extends DemoSeeder
{
    /**
     * Category, Arabic translation, kind, and eight plausible generic product families.
     * Avoid claiming fabricated manufacturer references are authentic.
     *
     * @var array<string, array{0:string,1:string,2:string,3:list<array{0:string,1:string}>}>
     */
    private const array Catalogue = [
        'dental-resins' => ['Dental Printing Resins', 'راتنجات الطباعة السنية', 'material', [
            ['Surgical Guide Resin', 'راتنج الدليل الجراحي'], ['Temporary Crown Resin', 'راتنج التاج المؤقت'], ['Dental Model Resin', 'راتنج النموذج السني'], ['Denture Base Resin', 'راتنج قاعدة الطقم'],
            ['Gingiva Mask Resin', 'راتنج محاكاة اللثة'], ['Clear Aligner Model Resin', 'راتنج نموذج التقويم الشفاف'], ['Castable Pattern Resin', 'راتنج نماذج الصب'], ['Flexible Splint Resin', 'راتنج الجبائر المرنة'],
        ]],
        'implantology' => ['Implantology Consumables', 'مستهلكات زراعة الأسنان', 'material', [
            ['Healing Abutment', 'دعامة الالتئام'], ['Implant Cover Screw', 'برغي غطاء الزرعة'], ['Prosthetic Screw', 'البرغي التعويضي'], ['Scan Body', 'جسم المسح الرقمي'],
            ['Impression Coping', 'ناقل الطبعة'], ['Implant Analog', 'نظير الزرعة'], ['Multi Unit Abutment', 'دعامة متعددة الوحدات'], ['Titanium Blank', 'كتلة تيتانيوم'],
        ]],
        'restorative' => ['Restorative Dentistry', 'مواد ترميم الأسنان', 'material', [
            ['Universal Composite', 'كمبوزيت ترميم شامل'], ['Flowable Composite', 'كمبوزيت سائل'], ['Glass Ionomer Cement', 'إسمنت أيونومير زجاجي'], ['Resin Cement', 'إسمنت راتنجي'],
            ['Dental Etchant Gel', 'جل التخريش السني'], ['Bonding Adhesive', 'مادة لاصقة سنية'], ['Temporary Filling Material', 'حشوة مؤقتة'], ['Core Build Up Composite', 'كمبوزيت بناء اللب'],
        ]],
        'endo' => ['Endodontic Materials', 'مواد معالجة الجذور', 'material', [
            ['Rotary Endodontic File', 'مبرد لبّي دوار'], ['Gutta Percha Points', 'أقماع غوتا بيركا'], ['Paper Points', 'أقماع ورقية'], ['Root Canal Sealer', 'مادة سد قناة الجذر'],
            ['Irrigation Needle', 'إبرة إرواء لبّي'], ['Endodontic Lubricant', 'مزلّق علاج الجذور'], ['Rubber Dam Sheet', 'حاجز مطاطي'], ['Apex Locator Probe', 'مسبار محدد الذروة'],
        ]],
        'orthodontic' => ['Orthodontics', 'مستلزمات تقويم الأسنان', 'material', [
            ['Orthodontic Bracket', 'حاصرة تقويمية'], ['Arch Wire', 'سلك قوس تقويمي'], ['Elastic Ligature', 'رباط تقويمي مطاطي'], ['Interproximal Strip', 'شريط بين الأسنان'],
            ['Retainer Sheet', 'صفيحة مثبت التقويم'], ['Clear Aligner Sheet', 'صفيحة التقويم الشفاف'], ['Buccal Tube', 'أنبوب شدقي'], ['Bondable Button', 'زر تقويمي لاصق'],
        ]],
        'infection-control' => ['Infection Control', 'مستلزمات مكافحة العدوى', 'material', [
            ['Examination Gloves', 'قفازات الفحص'], ['Sterilization Pouch', 'كيس التعقيم'], ['Surface Disinfectant', 'مطهر الأسطح'], ['Autoclave Indicator Strip', 'شريط مؤشر التعقيم'],
            ['Disposable Patient Bib', 'مريلة مريض'], ['Barrier Film', 'غشاء واقٍ'], ['Saliva Ejector', 'شفاط لعاب'], ['Surgical Face Mask', 'كمامة جراحية'],
        ]],
        'dental-lab' => ['Dental Laboratory Supplies', 'مستلزمات مخابر الأسنان', 'material', [
            ['Wax Pattern Block', 'كتلة شمع النمذجة'], ['Polishing Disc', 'قرص تلميع'], ['Lab Separator', 'مادة فصل مخبرية'], ['Dental Die Spacer', 'مباعد القالب السني'],
            ['CAD CAM Wax Disc', 'قرص شمع للتصميم الرقمي'], ['Abrasive Stone', 'حجر جلخ'], ['Glaze Paste', 'معجون تزجيج'], ['Laboratory Articulating Paper', 'ورق إطباق مخبري'],
        ]],
        'ceramics' => ['Dental Ceramics', 'سيراميك الأسنان', 'material', [
            ['Zirconia Disc', 'قرص زركونيا'], ['Lithium Disilicate Block', 'كتلة ديسيليكات الليثيوم'], ['Ceramic Stain Kit', 'مجموعة تلوين السيراميك'], ['Porcelain Powder', 'مسحوق البورسلان'],
            ['Firing Paste', 'معجون الحرق'], ['Ceramic Glaze Liquid', 'سائل تزجيج السيراميك'], ['Temporary PMMA Disc', 'قرص راتنج أكريليك مؤقت'], ['Hybrid Ceramic Block', 'كتلة سيراميك هجينة'],
        ]],
        'clinical-instruments' => ['Clinical Instruments', 'الأدوات السريرية', 'machine', [
            ['Ultrasonic Scaler', 'جهاز إزالة الجير بالموجات فوق الصوتية'], ['Curing Light', 'مصباح التصلب الضوئي'], ['Intraoral Camera', 'كاميرا داخل الفم'], ['Endodontic Motor', 'محرك علاج الجذور'],
            ['Apex Locator', 'محدد ذروة الجذر'], ['Air Polishing Unit', 'جهاز التلميع الهوائي'], ['Dental Handpiece', 'قبضة سنية'], ['Electric Micromotor', 'محرك سنّي صغير'],
        ]],
        'sterilization' => ['Sterilization Equipment', 'أجهزة التعقيم', 'machine', [
            ['Class B Autoclave', 'جهاز تعقيم من الفئة ب'], ['Instrument Washer', 'غسالة الأدوات'], ['Ultrasonic Cleaner', 'جهاز تنظيف بالموجات فوق الصوتية'], ['Sealing Machine', 'جهاز تغليف أكياس التعقيم'],
            ['UV Storage Cabinet', 'خزانة حفظ بالأشعة فوق البنفسجية'], ['Water Distiller', 'جهاز تقطير المياه'], ['Instrument Drying Cabinet', 'خزانة تجفيف الأدوات'], ['Sterilization Data Logger', 'مسجل بيانات التعقيم'],
        ]],
        'imaging' => ['Dental Imaging', 'التصوير السني', 'machine', [
            ['Intraoral Scanner', 'ماسح ضوئي داخل الفم'], ['Digital X Ray Sensor', 'حساس أشعة سينية رقمي'], ['Panoramic Imaging Console', 'وحدة تصوير بانورامي'], ['Imaging Plate Scanner', 'ماسح صفائح التصوير'],
            ['Camera Docking Station', 'قاعدة توصيل الكاميرا'], ['Cephalometric Positioner', 'مثبت تصوير رأسي'], ['Dental Monitor', 'شاشة عرض سنية'], ['Image Acquisition Module', 'وحدة التقاط الصور'],
        ]],
        'surgical' => ['Oral Surgery Supplies', 'مستلزمات جراحة الفم', 'material', [
            ['Suture Kit', 'مجموعة خياطة جراحية'], ['Surgical Irrigation Set', 'طقم إرواء جراحي'], ['Bone Graft Granules', 'حبيبات طعم عظمي'], ['Collagen Membrane', 'غشاء كولاجين'],
            ['Surgical Drill Sleeve', 'غلاف مثقاب جراحي'], ['Sterile Scalpel Blade', 'شفرة مشرط معقمة'], ['Surgical Suction Tip', 'رأس شفط جراحي'], ['Hemostatic Sponge', 'إسفنجة إيقاف النزف'],
        ]],
        'equipment-parts' => ['Equipment Spare Parts', 'قطع غيار أجهزة الأسنان', 'material', [
            ['Printer Resin Tank', 'حوض راتنج الطابعة'], ['Optical Window Protector', 'واقي النافذة البصرية'], ['Autoclave Door Gasket', 'حشية باب المعقم'], ['Handpiece Bearing Set', 'مجموعة محامل القبضة'],
            ['Scanner Calibration Target', 'هدف معايرة الماسح'], ['Pump Tubing Assembly', 'مجموعة أنابيب المضخة'], ['UV Lamp Module', 'وحدة مصباح فوق بنفسجي'], ['Chair Foot Control', 'دواسة تحكم الكرسي'],
        ]],
        'dental-powders' => ['Bulk Laboratory Powders', 'المساحيق المخبرية', 'grain', [
            ['Dental Stone Type IV', 'حجر سني من النوع الرابع'], ['Dental Plaster Type II', 'جبس سني من النوع الثاني'], ['Alginate Impression Powder', 'مسحوق ألجينات للطبعات'], ['Investment Material', 'مادة استثمار الصب'],
            ['Pumice Polishing Powder', 'مسحوق خفاف للتلميع'], ['Air Abrasion Powder', 'مسحوق السحل الهوائي'], ['Prophylaxis Powder', 'مسحوق تنظيف وقائي'], ['Refractory Die Material', 'مادة قالب حراري'],
        ]],
    ];

    protected function seed(DemoContext $context): void
    {
        $context->at(DemoContext::PeriodStart);
        $context->as('admin');

        $this->warehouses();
        $this->suppliers();
        $this->catalogue();
        $this->customers();
        $this->note('Expanded bilingual catalog, suppliers, warehouses and synthetic customer accounts are ready.');
    }

    private function warehouses(): void
    {
        foreach ([
            ['DEMO-WH-004', 'Abu Dhabi Fulfilment Hub', 24.4667, 54.3667],
            ['DEMO-WH-005', 'Sharjah Consumables Depot', 25.3463, 55.4209],
            ['DEMO-WH-006', 'Dubai Service Parts Store', 25.2048, 55.2708],
            ['DEMO-WH-007', 'Ajman Quality Quarantine', 25.4052, 55.5136],
        ] as [$code, $name, $lat, $lng]) {
            Warehouse::query()->updateOrCreate(['code' => $code], [
                'name' => $name, 'address' => $name.', UAE',
                'latitude' => $lat, 'longitude' => $lng, 'is_active' => true,
            ]);
        }
    }

    private function suppliers(): void
    {
        $names = [
            'Gulf Sterile Supply', 'Emirates Dental Logistics', 'Falcon Orthodontic Trade',
            'Pearl Dental Laboratory Supply', 'Desert Care Medical Distribution',
            'Crescent Clinical Instruments', 'Marina Implant Materials', 'Oasis Surgery Supply',
            'Coastal Restorative Distribution', 'Al Safa Prosthetics Trade',
            'Northern Emirates Hygiene', 'Capital Dental Hardware',
            'Al Noor Laboratory Essentials', 'Delta Clinical Equipment',
            'Golden Palm Medical Warehouse', 'Sharjah Surgical Supply',
            'Blue Dune Dental Solutions', 'Sahel Imaging Distribution',
        ];

        foreach ($names as $i => $name) {
            $n = $i + 8;
            $code = sprintf('DEMO-SUP-%03d', $n);
            Supplier::query()->updateOrCreate(['code' => $code], [
                'name' => $name.' (demo)', 'email' => sprintf('orders%03d@supplier.test', $n),
                'phone' => sprintf('+9714009%04d', $n),
                'address' => ['Dubai', 'Sharjah', 'Abu Dhabi', 'Ajman'][$i % 4].', UAE',
                'default_currency_code' => 'AED',
                'default_lead_time_days' => 2 + $i % 12,
                'requires_confirmation' => $i % 4 === 0,
                'is_active' => true,
            ]);
        }
    }

    private function catalogue(): void
    {
        $unit = Unit::query()->where('code', 'EA')->firstOrFail();
        $kg = Unit::query()->where('code', 'KG')->firstOrFail();
        $suppliers = Supplier::query()->where('code', 'like', 'DEMO-SUP-%')->orderBy('code')->get();
        $supplierCount = $suppliers->count();

        $index = 0;
        foreach (self::Catalogue as $categoryCode => [$english, $arabic, $kind, $families]) {
            $category = ProductCategory::query()->updateOrCreate(['name' => $english], [
                'name_ar' => $arabic, 'is_active' => true,
            ]);
            $type = match ($kind) {
                'machine' => ProductType::Machine,
                'grain' => ProductType::Grain,
                default => ProductType::ExpiryMaterial,
            };
            $packages = match ($kind) {
                'machine' => [['220V', '220 فولت', 1.0], ['110V', '110 فولت', 1.02], ['Premium', 'نسخة متقدمة', 1.35]],
                'grain' => [['1kg', '1 كغ', 1.0], ['5kg', '5 كغ', 4.65], ['25kg', '25 كغ', 22.0]],
                default => [['Single', 'عبوة مفردة', 1.0], ['Pack5', 'عبوة 5', 4.7], ['Pack20', 'عبوة 20', 17.6]],
            };

            foreach ($families as $offset => [$name, $nameAr]) {
                $index++;
                $product = Product::query()->updateOrCreate(['name' => '[Demo] '.$name], [
                    'name_ar' => $nameAr,
                    'description' => 'Illustrative dental distribution item. Synthetic, not a manufacturer part number.',
                    'category_id' => $category->getKey(),
                    'product_type' => $type, 'status' => 'active', 'is_active' => true,
                ]);
                $product->addAllowedUnit($unit);

                foreach ($packages as [$suffix, $suffixAr, $factor]) {
                    $sku = sprintf('DEMO-REAL-%03d-%s', $index, $suffix);
                    $baseCost = match ($kind) {
                        'machine' => 850.0 + ($offset * 670),
                        'grain' => 36.0 + ($offset * 9),
                        default => 17.0 + ($offset * 18),
                    };
                    $cost = round($baseCost * $factor, 2);
                    $price = round($cost * (1.28 + ($index % 5) * 0.07), 2);
                    $variant = ProductVariant::query()->updateOrCreate(['sku' => $sku], [
                        ...$type->trackingFlags(),
                        'product_id' => $product->getKey(),
                        'name' => $name.' — '.$suffix, 'name_ar' => $nameAr.' — '.$suffixAr,
                        'unit_id' => $unit->getKey(),
                        'cost_price' => $cost, 'base_price' => $price,
                        'min_price' => round($price * 0.79, 2),
                        'markup_percent' => round(($price - $cost) / $cost * 100, 2),
                        'net_weight' => $kind === 'grain' ? (float) $factor : null,
                        'weight_unit_id' => $kind === 'grain' ? $kg->getKey() : null,
                        'status' => 'active', 'is_active' => true,
                    ]);
                    app(ProductVariantUomService::class)->sync($variant, [[
                        'unit_id' => $unit->getKey(), 'is_base' => true,
                        'is_purchase' => true, 'is_sale' => true, 'is_display' => true,
                        'factor_to_base' => '1', 'rounding_increment' => '1',
                        'permits_cross_family_conversion' => false, 'is_active' => true,
                    ]]);

                    if ($supplierCount > 0) {
                        $supplier = $suppliers[($index + mb_strlen($suffix)) % $supplierCount]
                            ?? throw new \LogicException('Missing expanded supplier.');
                        SupplierProductReference::query()->updateOrCreate([
                            'supplier_id' => $supplier->getKey(),
                            'product_variant_id' => $variant->getKey(),
                        ], [
                            'supplier_name' => $supplier->name,
                            'supplier_item_number' => sprintf('SYN-%03d-%s', $index, $suffix),
                            'country_code' => 'AE',
                            'manufacturer' => 'Synthetic demo catalogue',
                            'purchase_cost' => $cost,
                            'currency_code' => 'AED',
                            'notes' => 'Fictional supplier reference for training.',
                            'availability_status' => 'active',
                            'lead_time_days' => 2 + $index % 14,
                            'minimum_order_quantity' => 1,
                            'is_preferred' => true, 'is_active' => true,
                        ]);
                    }
                }
            }
        }
    }

    private function customers(): void
    {
        $provisioner = app(CustomerAccountProvisioningService::class);
        $terms = PaymentTerm::query()->pluck('id', 'name');
        $cities = [
            ['Dubai', 25.2048, 55.2708], ['Abu Dhabi', 24.4539, 54.3773],
            ['Sharjah', 25.3463, 55.4209], ['Ajman', 25.4052, 55.5136],
            ['Al Ain', 24.1302, 55.8023], ['Ras Al Khaimah', 25.7895, 55.9432],
            ['Fujairah', 25.1288, 56.3265],
        ];
        $prefixes = ['Al Noor', 'Crescent', 'Pearl', 'Oasis', 'Bright Smile', 'Royal Palm', 'Marina', 'Golden Bay', 'Al Amal', 'Sapphire', 'Green Valley', 'Horizon'];
        $specialties = ['Dental Clinic', 'Orthodontic Center', 'Dental Laboratory', 'Implant Center', 'Medical Polyclinic', 'Restorative Dentistry'];
        $termNames = ['Net 7', 'Net 15', 'Net 30', 'Net 45'];

        for ($n = 22; $n <= 145; $n++) {
            $code = sprintf('DEMO-CUST-%03d', $n);
            if (CustomerProfile::query()->where('customer_code', $code)->exists()) {
                continue;
            }
            [$city, $lat, $lng] = $cities[$n % count($cities)];
            $company = $prefixes[$n % count($prefixes)].' '.$specialties[(int) floor($n / count($prefixes)) % count($specialties)].' — '.$city.' '.sprintf('%03d', $n);

            $profile = $provisioner->provision([
                'name' => $company,
                'username' => sprintf('demo-cust-%03d', $n),
                'email' => sprintf('demo.cust%03d@ierp.test', $n),
                'password' => DemoContext::Password,
            ], [
                'customer_code' => $code, 'company_name' => $company,
                'email' => sprintf('accounts.cust%03d@clinic.test', $n),
                'phone' => sprintf('+9715071%05d', $n),
                'address' => sprintf('Healthcare District, Building %03d, %s, UAE', $n, $city),
                'country' => 'AE', 'city' => $city,
                'latitude' => $lat + (($n % 9) * 0.001),
                'longitude' => $lng + (($n % 11) * 0.001),
                'contact_is_self' => true, 'is_active' => true,
                'allow_direct_orders' => $n % 7 !== 0,
            ], [], CustomerProvisioningSource::Dashboard);

            if ($termId = ($terms[$termNames[$n % count($termNames)]] ?? null)) {
                $profile->forceFill(['default_payment_term_id' => $termId])->save();
            }
        }
    }
}
