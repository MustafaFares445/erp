<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CustomerProfile;
use App\Services\Audit\AuditLogger;

final readonly class CustomerProfileObserver
{
    public function __construct(
        private AuditLogger $auditLogger,
    ) {}

    public function created(CustomerProfile $customerProfile): void
    {
        $this->auditLogger->log(
            action: 'customer.created',
            entity: $customerProfile,
            newValues: $customerProfile->getAttributes(),
        );
    }

    public function updated(CustomerProfile $customerProfile): void
    {
        $action = $customerProfile->wasChanged('is_active') && ! $customerProfile->is_active
            ? 'customer.deactivated'
            : 'customer.updated';

        $this->auditLogger->log(
            action: $action,
            entity: $customerProfile,
            oldValues: $customerProfile->getOriginal(),
            newValues: $customerProfile->getAttributes(),
        );
    }

    public function deleted(CustomerProfile $customerProfile): void
    {
        $this->auditLogger->log(
            action: 'customer.deleted',
            entity: $customerProfile,
            oldValues: $customerProfile->getOriginal(),
        );
    }

    public function restored(CustomerProfile $customerProfile): void
    {
        $this->auditLogger->log(
            action: 'customer.restored',
            entity: $customerProfile,
            newValues: $customerProfile->getAttributes(),
        );
    }
}
