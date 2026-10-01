<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

final class MaintenanceRecordPolicy
{
    use ChecksSupportPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'create');
    }

    /**
     * Editing the request's details is refused once the request is closed,
     * cancelled or commercially billed. Workflow status changes use
     * {@see self::transition()} so a quoted request can still move forward.
     */
    public function update(User $user, ?MaintenanceRecord $record = null): bool
    {
        if ($record instanceof MaintenanceRecord && $record->isLockedForChanges()) {
            return false;
        }

        return $this->authorizeSupportAbility($user, 'update');
    }

    /**
     * Moving the request through its lifecycle (approval, repair, QA, close,
     * cancel) — allowed regardless of billing state; the status enum owns
     * which edges exist.
     */
    public function transition(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'transition');
    }

    public function delete(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'deleteAny');
    }

    /**
     * Restoration is System-Admin-only (FR-001, User Story 1 scenario 2) —
     * never granted to Support Manager, unlike ordinary maintenance-request
     * management.
     */
    public function restore(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'restoreAny');
    }

    /**
     * Viewing job-cost figures (WP-2.9, GAP-MW-09).
     */
    public function viewCost(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'viewCost');
    }

    /**
     * Recording labour time or third-party cost against the job (WP-2.9,
     * GAP-MW-09).
     */
    public function recordCost(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'recordCost');
    }

    /**
     * Marking a job warranty-covered, or converting it to a quotation or
     * invoice (WP-2.9, GAP-MW-10).
     */
    public function bill(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'bill');
    }

    public function diagnose(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'diagnose');
    }

    public function decideCoverage(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'decideCoverage');
    }

    public function overrideWarranty(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'overrideWarranty');
    }

    /** @return array<string, string> */
    protected function supportPermissionMap(): array
    {
        return [
            'viewAny' => SupportPermission::MaintenanceRequestView->value,
            'view' => SupportPermission::MaintenanceRequestView->value,
            'create' => SupportPermission::MaintenanceRequestManage->value,
            'update' => SupportPermission::MaintenanceRequestManage->value,
            'transition' => SupportPermission::MaintenanceRequestManage->value,
            'delete' => SupportPermission::MaintenanceRequestManage->value,
            'deleteAny' => SupportPermission::MaintenanceRequestManage->value,
            'restore' => SupportPermission::RecordRestore->value,
            'restoreAny' => SupportPermission::RecordRestore->value,
            'viewCost' => SupportPermission::MaintenanceCostView->value,
            'recordCost' => SupportPermission::MaintenanceCostRecord->value,
            'bill' => SupportPermission::MaintenanceCostBill->value,
            'diagnose' => SupportPermission::MaintenanceDiagnosisRecord->value,
            'decideCoverage' => SupportPermission::WarrantyCoverageDecide->value,
            'overrideWarranty' => SupportPermission::WarrantyOverride->value,
        ];
    }
}
