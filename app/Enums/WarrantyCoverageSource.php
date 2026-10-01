<?php

declare(strict_types=1);

namespace App\Enums;

enum WarrantyCoverageSource: string
{
    case SellerWarranty = 'seller_warranty';
    case ManufacturerWarranty = 'manufacturer_warranty';
    case SupplierWarranty = 'supplier_warranty';
    case ServiceContract = 'service_contract';
    case Goodwill = 'goodwill';
    case CustomerPaid = 'customer_paid';

    public function label(): string
    {
        return match ($this) {
            self::SellerWarranty => 'IERP seller warranty',
            self::ManufacturerWarranty => 'Manufacturer warranty',
            self::SupplierWarranty => 'Supplier warranty',
            self::ServiceContract => 'Service contract',
            self::Goodwill => 'Goodwill / commercial courtesy',
            self::CustomerPaid => 'Customer paid',
        };
    }
}
