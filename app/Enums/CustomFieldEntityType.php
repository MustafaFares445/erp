<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Closed allow-list for metadata fields. Financial and stock-ledger records
 * are deliberately absent so custom metadata can never redefine accounting,
 * tax, payment, invoice-total, stock, or movement facts.
 */
enum CustomFieldEntityType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Product = 'product';
    case Lead = 'lead';
    case Ticket = 'ticket';
    case Maintenance = 'maintenance';

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Customer => CustomerProfile::class,
            self::Supplier => Supplier::class,
            self::Product => Product::class,
            self::Lead => Lead::class,
            self::Ticket => Ticket::class,
            self::Maintenance => MaintenanceRecord::class,
        };
    }

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }

    public static function fromModel(Model $model): ?self
    {
        foreach (self::cases() as $case) {
            if ($model instanceof ($case->modelClass())) {
                return $case;
            }
        }

        return null;
    }
}
