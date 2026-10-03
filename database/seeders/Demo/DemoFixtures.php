<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * Static, deterministic fixture tables for the demo month. No randomness anywhere:
 * every business value the dashboards depend on lives in these arrays.
 */
final class DemoFixtures
{
    /** @var array<string, array{name: string, term: string, city: string, lat: float, lng: float}> */
    public const array Customers = [
        'DEMO-CUST-001' => ['name' => 'Al Noor Medical Center', 'term' => 'Net 30', 'city' => 'Dubai', 'lat' => 25.2048, 'lng' => 55.2708],
        'DEMO-CUST-002' => ['name' => 'Bright Smile Dental Clinic', 'term' => 'Net 15', 'city' => 'Dubai', 'lat' => 25.2285, 'lng' => 55.3273],
        'DEMO-CUST-003' => ['name' => 'Al Hayat Day Surgery Center', 'term' => 'Net 45', 'city' => 'Abu Dhabi', 'lat' => 24.4539, 'lng' => 54.3773],
        'DEMO-CUST-004' => ['name' => 'Pearl Dental Center', 'term' => 'Net 30', 'city' => 'Sharjah', 'lat' => 25.3463, 'lng' => 55.4209],
        'DEMO-CUST-005' => ['name' => 'New Care Medical Center', 'term' => 'Net 15', 'city' => 'Ajman', 'lat' => 25.4052, 'lng' => 55.5136],
        'DEMO-CUST-006' => ['name' => 'City Diagnostic Clinic', 'term' => 'Net 7', 'city' => 'Dubai', 'lat' => 25.1972, 'lng' => 55.2744],
        'DEMO-CUST-007' => ['name' => 'Prime Dental Laboratory', 'term' => 'Net 30', 'city' => 'Sharjah', 'lat' => 25.3573, 'lng' => 55.3913],
        'DEMO-CUST-008' => ['name' => 'Green Valley Clinic', 'term' => 'Net 15', 'city' => 'Al Ain', 'lat' => 24.2075, 'lng' => 55.7447],
        'DEMO-CUST-009' => ['name' => 'Royal Medical Center', 'term' => 'Net 7', 'city' => 'Abu Dhabi', 'lat' => 24.4667, 'lng' => 54.3667],
        'DEMO-CUST-010' => ['name' => 'Future Health Clinic', 'term' => 'Net 30', 'city' => 'Dubai', 'lat' => 25.0657, 'lng' => 55.1713],
        'DEMO-CUST-011' => ['name' => 'Modern Dental Lab', 'term' => 'Net 45', 'city' => 'Ras Al Khaimah', 'lat' => 25.7895, 'lng' => 55.9432],
        'DEMO-CUST-012' => ['name' => 'Elite Surgical Center', 'term' => 'Net 15', 'city' => 'Abu Dhabi', 'lat' => 24.4764, 'lng' => 54.3705],
        'DEMO-CUST-013' => ['name' => 'Harmony Dental Clinic', 'term' => 'Net 30', 'city' => 'Dubai', 'lat' => 25.2769, 'lng' => 55.2962],
        'DEMO-CUST-014' => ['name' => 'Advanced Care Center', 'term' => 'Net 7', 'city' => 'Fujairah', 'lat' => 25.1288, 'lng' => 56.3265],
        'DEMO-CUST-015' => ['name' => 'Family Medical Center', 'term' => 'Net 30', 'city' => 'Sharjah', 'lat' => 25.3375, 'lng' => 55.3951],
    ];

    /** @var array<string, array{name: string, confirm: bool, country: string}> */
    public const array Suppliers = [
        'DEMO-SUP-001' => ['name' => 'MedSupply Gulf', 'confirm' => false, 'country' => 'AE'],
        'DEMO-SUP-002' => ['name' => 'DentalTech Distribution', 'confirm' => false, 'country' => 'AE'],
        'DEMO-SUP-003' => ['name' => 'Surgical Materials Trading', 'confirm' => true, 'country' => 'AE'],
        'DEMO-SUP-004' => ['name' => 'Precision Medical Supplies', 'confirm' => false, 'country' => 'DE'],
        'DEMO-SUP-005' => ['name' => 'HealthLine Equipment', 'confirm' => true, 'country' => 'AE'],
        'DEMO-SUP-006' => ['name' => 'ProDent Materials', 'confirm' => false, 'country' => 'CH'],
        'DEMO-SUP-007' => ['name' => 'Global Clinical Supplies', 'confirm' => false, 'country' => 'AE'],
    ];

    /** @var array<string, array{name: string, lat: float, lng: float}> */
    public const array Warehouses = [
        'WH-MAIN' => ['name' => 'Main Clinic Store', 'lat' => 25.1255, 'lng' => 55.2300],
        'WH-COLD' => ['name' => 'Cold Chain Storage', 'lat' => 25.0050, 'lng' => 55.1750],
        'WH-REPAIR' => ['name' => 'Repair Bench', 'lat' => 25.1190, 'lng' => 55.2410],
    ];

    /** @var array<string, string> */
    public const array Categories = [
        'dental-materials' => 'Dental Materials',
        'surgical-supplies' => 'Surgical Supplies',
        'impression-materials' => 'Impression Materials',
        'prosthetic-materials' => 'Prosthetic Materials',
        'sterilization-supplies' => 'Sterilization Supplies',
        'equipment-accessories' => 'Equipment Accessories',
    ];

    /**
     * Catalogue. Product code => name, category, tracking type ('expiry' | 'grain' | 'machine'),
     * supplier codes able to supply it, and variants.
     * Variant: [suffix, name, cost, price, [main, cold, repair] opening quantity, [min, max] policy at WH-MAIN].
     *
     * @var array<string, array{name: string, category: string, type: string, suppliers: list<string>, variants: list<array{0: string, 1: string, 2: float, 3: float, 4: array{0: int, 1: int, 2: int}, 5: array{0: int, 1: int}}>}>
     */
    public const array Products = [
        'P001' => ['name' => 'Surgical Guide Resin', 'category' => 'dental-materials', 'type' => 'expiry', 'suppliers' => ['DEMO-SUP-002'], 'variants' => [
            ['500ML', '500 ml', 180.0, 265.0, [24, 0, 0], [10, 50]],
            ['1L', '1 L', 320.0, 470.0, [16, 0, 0], [8, 30]],
        ]],
        'P002' => ['name' => 'Model Resin', 'category' => 'dental-materials', 'type' => 'expiry', 'suppliers' => ['DEMO-SUP-002'], 'variants' => [
            ['1L', '1 L', 150.0, 225.0, [40, 0, 0], [15, 80]],
            ['5L', '5 L', 640.0, 940.0, [6, 0, 0], [6, 20]],
        ]],
        'P003' => ['name' => 'Temporary Crown Resin', 'category' => 'prosthetic-materials', 'type' => 'expiry', 'suppliers' => ['DEMO-SUP-002'], 'variants' => [
            ['250ML', '250 ml', 210.0, 310.0, [12, 0, 0], [5, 30]],
            ['1L', '1 L', 700.0, 1020.0, [3, 0, 0], [4, 12]],
        ]],
        'P004' => ['name' => 'Dental Implant Fixture', 'category' => 'surgical-supplies', 'type' => 'expiry', 'suppliers' => ['DEMO-SUP-003'], 'variants' => [
            ['35X10', '3.5 x 10 mm', 260.0, 420.0, [24, 0, 0], [10, 60]],
            ['35X12', '3.5 x 12 mm', 270.0, 440.0, [22, 0, 0], [10, 50]],
            ['40X10', '4.0 x 10 mm', 275.0, 450.0, [9, 0, 0], [10, 40]],
            ['40X12', '4.0 x 12 mm', 285.0, 470.0, [6, 0, 0], [8, 40]],
        ]],
        'P005' => ['name' => 'Healing Abutment', 'category' => 'surgical-supplies', 'type' => 'expiry', 'suppliers' => ['DEMO-SUP-003'], 'variants' => [
            ['35MM', '3.5 mm', 60.0, 110.0, [50, 0, 0], [20, 120]],
            ['45MM', '4.5 mm', 65.0, 120.0, [45, 0, 0], [20, 100]],
        ]],
        'P006' => ['name' => 'Impression Material', 'category' => 'impression-materials', 'type' => 'expiry', 'suppliers' => ['DEMO-SUP-006', 'DEMO-SUP-007'], 'variants' => [
            ['LIGHT', 'Light body', 45.0, 85.0, [40, 0, 0], [20, 90]],
            ['HEAVY', 'Heavy body', 52.0, 95.0, [36, 0, 0], [20, 90]],
            ['PUTTY', 'Putty', 58.0, 105.0, [20, 0, 0], [20, 80]],
        ]],
        'P007' => ['name' => 'Surgical Gloves', 'category' => 'surgical-supplies', 'type' => 'expiry', 'suppliers' => ['DEMO-SUP-001', 'DEMO-SUP-007'], 'variants' => [
            ['S', 'Small (box of 100)', 18.0, 32.0, [60, 0, 0], [30, 150]],
            ['M', 'Medium (box of 100)', 18.0, 32.0, [80, 0, 0], [30, 200]],
            ['L', 'Large (box of 100)', 18.0, 32.0, [9, 0, 0], [25, 150]],
        ]],
        'P008' => ['name' => 'Sterilization Pouch', 'category' => 'sterilization-supplies', 'type' => 'expiry', 'suppliers' => ['DEMO-SUP-001', 'DEMO-SUP-007'], 'variants' => [
            ['90X230', '90 x 230 mm (box of 200)', 12.0, 22.0, [120, 0, 0], [60, 250]],
            ['135X280', '135 x 280 mm (box of 200)', 15.0, 27.0, [30, 0, 0], [40, 200]],
        ]],
        'P009' => ['name' => 'Dental Cement', 'category' => 'dental-materials', 'type' => 'expiry', 'suppliers' => ['DEMO-SUP-006'], 'variants' => [
            ['STD', 'Standard kit', 85.0, 140.0, [30, 0, 0], [12, 60]],
        ]],
        'P010' => ['name' => 'Composite Resin', 'category' => 'dental-materials', 'type' => 'expiry', 'suppliers' => ['DEMO-SUP-006'], 'variants' => [
            ['A2', 'Shade A2', 95.0, 165.0, [26, 0, 0], [10, 50]],
            ['A3', 'Shade A3', 95.0, 165.0, [4, 0, 0], [10, 50]],
        ]],
        'P011' => ['name' => 'Bonding Agent', 'category' => 'dental-materials', 'type' => 'expiry', 'suppliers' => ['DEMO-SUP-006'], 'variants' => [
            ['5ML', '5 ml bottle', 110.0, 185.0, [8, 14, 0], [10, 40]],
        ]],
        'P012' => ['name' => 'Endodontic File Set', 'category' => 'dental-materials', 'type' => 'grain', 'suppliers' => ['DEMO-SUP-004'], 'variants' => [
            ['21MM', '21 mm', 75.0, 130.0, [20, 0, 0], [8, 40]],
            ['25MM', '25 mm', 80.0, 140.0, [8, 0, 0], [8, 40]],
        ]],
        'P013' => ['name' => 'Irrigation Solution', 'category' => 'sterilization-supplies', 'type' => 'expiry', 'suppliers' => ['DEMO-SUP-001', 'DEMO-SUP-007'], 'variants' => [
            ['500ML', '500 ml', 14.0, 27.0, [60, 0, 0], [20, 120]],
            ['1L', '1 L', 22.0, 40.0, [40, 0, 0], [15, 80]],
        ]],
        'P014' => ['name' => 'Bone Graft Material', 'category' => 'surgical-supplies', 'type' => 'expiry', 'suppliers' => ['DEMO-SUP-003'], 'variants' => [
            ['05G', '0.5 g', 230.0, 390.0, [0, 12, 0], [6, 30]],
            ['10G', '1.0 g', 410.0, 690.0, [0, 8, 0], [5, 20]],
        ]],
        'P015' => ['name' => 'Membrane Sheet', 'category' => 'surgical-supplies', 'type' => 'expiry', 'suppliers' => ['DEMO-SUP-003'], 'variants' => [
            ['15X20', '15 x 20 mm', 190.0, 320.0, [0, 16, 0], [6, 30]],
            ['20X30', '20 x 30 mm', 270.0, 450.0, [0, 9, 0], [5, 20]],
        ]],
        'P016' => ['name' => 'Dental Bur Set', 'category' => 'equipment-accessories', 'type' => 'grain', 'suppliers' => ['DEMO-SUP-004'], 'variants' => [
            ['BASIC', 'Basic set', 120.0, 210.0, [10, 0, 10], [8, 40]],
            ['PREMIUM', 'Premium set', 210.0, 360.0, [8, 0, 0], [4, 20]],
        ]],
        'P017' => ['name' => 'Scan Body', 'category' => 'prosthetic-materials', 'type' => 'grain', 'suppliers' => ['DEMO-SUP-002', 'DEMO-SUP-005'], 'variants' => [
            ['STD', 'Standard', 95.0, 170.0, [18, 0, 0], [8, 40]],
            ['LONG', 'Long', 105.0, 185.0, [5, 0, 0], [6, 30]],
        ]],
        'P018' => ['name' => 'Impression Tray', 'category' => 'impression-materials', 'type' => 'grain', 'suppliers' => ['DEMO-SUP-001', 'DEMO-SUP-005'], 'variants' => [
            ['UPPER', 'Upper', 25.0, 45.0, [40, 0, 0], [15, 80]],
            ['LOWER', 'Lower', 25.0, 45.0, [36, 0, 0], [15, 80]],
        ]],
        'P019' => ['name' => 'Surgical Drill', 'category' => 'surgical-supplies', 'type' => 'machine', 'suppliers' => ['DEMO-SUP-004', 'DEMO-SUP-005'], 'variants' => [
            ['HANDPIECE', 'Handpiece', 2800.0, 4200.0, [2, 0, 1], [1, 6]],
            ['MOTOR', 'Motor unit', 6100.0, 8900.0, [1, 0, 0], [1, 4]],
        ]],
        'P020' => ['name' => 'Maintenance Kit', 'category' => 'equipment-accessories', 'type' => 'grain', 'suppliers' => ['DEMO-SUP-004', 'DEMO-SUP-005'], 'variants' => [
            ['BASIC', 'Basic kit', 150.0, 260.0, [4, 0, 16], [6, 30]],
            ['PRO', 'Pro kit', 330.0, 540.0, [3, 0, 8], [4, 20]],
        ]],
    ];

    /** @var array<string, array{name: string, days: int, grace: int}> */
    public const array PaymentTerms = [
        'Net 7' => ['name' => 'Net 7', 'days' => 7, 'grace' => 0],
        'Net 15' => ['name' => 'Net 15', 'days' => 15, 'grace' => 3],
        'Net 30' => ['name' => 'Net 30', 'days' => 30, 'grace' => 5],
        'Net 45' => ['name' => 'Net 45', 'days' => 45, 'grace' => 5],
    ];
}
