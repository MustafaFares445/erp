<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PackageType;
use Illuminate\Validation\ValidationException;

final class PackageTypeObserver
{
    public function deleting(PackageType $packageType): void
    {
        if ($packageType->isReferenced()) {
            throw ValidationException::withMessages([
                'package_type' => __('admin.package.errors.type_referenced'),
            ]);
        }
    }
}
