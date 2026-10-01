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
            self::SellerWarranty => __(__('IERP seller warranty')),
            self::ManufacturerWarranty => __(__('Manufacturer warranty')),
            self::SupplierWarranty => __(__('Supplier warranty')),
            self::ServiceContract => __(__('Service contract')),
            self::Goodwill => __(__('Goodwill / commercial courtesy')),
            self::CustomerPaid => __(__('Customer paid')),
        };
    }
}
