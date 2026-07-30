<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ProductSubscription;
use App\Services\Audit\AuditLogger;

final readonly class ProductSubscriptionObserver
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function created(ProductSubscription $productSubscription): void
    {
        $this->log('subscription.created', $productSubscription);
    }

    public function updated(ProductSubscription $productSubscription): void
    {
        $action = match (true) {
            $productSubscription->wasChanged('is_active') && $productSubscription->is_active => 'subscription.activated',
            $productSubscription->wasChanged('is_active') => 'subscription.deactivated',
            default => 'subscription.updated',
        };

        $this->log($action, $productSubscription, $productSubscription->getOriginal());
    }

    public function deleted(ProductSubscription $productSubscription): void
    {
        $this->log('subscription.deleted', $productSubscription, $productSubscription->getOriginal());
    }

    public function restored(ProductSubscription $productSubscription): void
    {
        $this->log('subscription.restored', $productSubscription);
    }

    public function restoring(ProductSubscription $productSubscription): void
    {
        $productSubscription->is_active = false;
    }

    /** @param array<string, mixed>|null $oldValues */
    private function log(string $action, ProductSubscription $subscription, ?array $oldValues = null): void
    {
        $this->auditLogger->log(
            action: $action,
            entity: $subscription,
            oldValues: $oldValues,
            newValues: $subscription->getAttributes(),
        );
    }
}
