<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CustomerProfile;

final readonly class CustomerProfileObserver
{
    public function created(CustomerProfile $customerProfile): void
    {
        activity()
            ->performedOn($customerProfile)
            ->withChanges(['attributes' => $customerProfile->getAttributes()])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('customer.created');
    }

    public function updated(CustomerProfile $customerProfile): void
    {
        $action = $customerProfile->wasChanged('is_active') && ! $customerProfile->is_active
            ? 'customer.deactivated'
            : 'customer.updated';

        activity()
            ->performedOn($customerProfile)
            ->withChanges([
                'old' => $customerProfile->getOriginal(),
                'attributes' => $customerProfile->getAttributes(),
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log($action);
    }

    public function deleted(CustomerProfile $customerProfile): void
    {
        activity()
            ->performedOn($customerProfile)
            ->withChanges(['old' => $customerProfile->getOriginal()])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('customer.deleted');
    }

    public function restored(CustomerProfile $customerProfile): void
    {
        activity()
            ->performedOn($customerProfile)
            ->withChanges(['attributes' => $customerProfile->getAttributes()])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('customer.restored');
    }
}
