<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Package;
use Illuminate\Validation\ValidationException;

final class PackageObserver
{
    public function saving(Package $package): void
    {
        if (! $package->hasValidLocation()) {
            throw ValidationException::withMessages([
                'warehouse_location_id' => __('admin.package.errors.location_mismatch'),
            ]);
        }

        if ($package->shouldRejectWarehouseMove()) {
            throw ValidationException::withMessages([
                'warehouse_id' => __('admin.package.errors.warehouse_move_with_goods'),
            ]);
        }
    }

    public function deleting(Package $package): void
    {
        if ($package->isReferenced()) {
            throw ValidationException::withMessages([
                'package' => __('admin.package.errors.referenced'),
            ]);
        }
    }
}
