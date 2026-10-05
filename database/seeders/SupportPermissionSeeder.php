<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SupportPermission;
use Database\Seeders\Concerns\SeedsPermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

final class SupportPermissionSeeder extends Seeder
{
    use SeedsPermissionCatalog;

    public function run(): void
    {
        $this->seedPermissionCatalog(SupportPermission::values());

        foreach ($this->rolePermissions() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permissions);
        }
    }

    /** @return array<string, list<string>> */
    private function rolePermissions(): array
    {
        return [
            'System Admin' => SupportPermission::values(),
            'Support Manager' => [
                SupportPermission::TicketView->value,
                SupportPermission::TicketManage->value,
                SupportPermission::TicketAssign->value,
                SupportPermission::TicketMessage->value,
                SupportPermission::SlaPolicyView->value,
                SupportPermission::SlaPolicyManage->value,
                SupportPermission::MaintenanceRequestView->value,
                SupportPermission::MaintenanceRequestManage->value,
                SupportPermission::ServiceRecordView->value,
                SupportPermission::ServiceRecordManage->value,
                SupportPermission::PartsConsume->value,
                SupportPermission::ReportView->value,
                SupportPermission::AuditView->value,
                SupportPermission::MaintenanceCostView->value,
                SupportPermission::MaintenanceCostRecord->value,
                SupportPermission::MaintenanceCostBill->value,
                SupportPermission::MaintenanceScheduleView->value,
                SupportPermission::MaintenanceScheduleManage->value,
                SupportPermission::MaintenanceDiagnosisRecord->value,
                SupportPermission::WarrantyCoverageDecide->value,
                SupportPermission::WarrantyOverride->value,
                SupportPermission::WarrantyPolicyView->value,
                SupportPermission::WarrantyPolicyManage->value,
                SupportPermission::WarrantyRecoveryView->value,
                SupportPermission::WarrantyRecoveryManage->value,
                SupportPermission::TeamView->value,
                SupportPermission::TeamManage->value,
                SupportPermission::QueueView->value,
                SupportPermission::QueueManage->value,
                SupportPermission::RoutingRuleView->value,
                SupportPermission::RoutingRuleManage->value,
                SupportPermission::TicketRoute->value,
                SupportPermission::AutomationView->value,
                SupportPermission::AutomationManage->value,
                SupportPermission::ServiceAppointmentView->value,
                SupportPermission::ServiceAppointmentManage->value,
                SupportPermission::ServiceAppointmentExecute->value,
                SupportPermission::Equipment360View->value,
                SupportPermission::KnowledgeView->value,
                SupportPermission::KnowledgeManage->value,
                SupportPermission::KnowledgePublish->value,
                SupportPermission::CsatView->value,
                SupportPermission::SlaCalendarView->value,
                SupportPermission::SlaCalendarManage->value,
                SupportPermission::ServiceLevelView->value,
                SupportPermission::ServiceLevelManage->value,
                SupportPermission::EntitlementView->value,
                SupportPermission::EntitlementManage->value,
                SupportPermission::InstallationView->value,
                SupportPermission::InstallationManage->value,
                SupportPermission::InstallationComplete->value,
                SupportPermission::CalibrationView->value,
                SupportPermission::CalibrationManage->value,
                SupportPermission::CalibrationComplete->value,
                SupportPermission::LoanView->value,
                SupportPermission::LoanManage->value,
                SupportPermission::RmaView->value,
                SupportPermission::RmaManage->value,
                SupportPermission::QualityComplaintView->value,
                SupportPermission::QualityComplaintManage->value,
            ],
            'Support Agent' => [
                SupportPermission::TicketView->value,
                SupportPermission::TicketWork->value,
                SupportPermission::TicketMessage->value,
                SupportPermission::MaintenanceRequestView->value,
                SupportPermission::ServiceRecordView->value,
                SupportPermission::ServiceRecordExecute->value,
                SupportPermission::PartsConsume->value,
                SupportPermission::MaintenanceCostView->value,
                SupportPermission::MaintenanceCostRecord->value,
                SupportPermission::MaintenanceScheduleView->value,
                SupportPermission::MaintenanceDiagnosisRecord->value,
                SupportPermission::WarrantyPolicyView->value,
                SupportPermission::WarrantyRecoveryView->value,
                SupportPermission::ServiceAppointmentView->value,
                SupportPermission::ServiceAppointmentExecute->value,
                SupportPermission::Equipment360View->value,
                SupportPermission::KnowledgeView->value,
                SupportPermission::InstallationView->value,
                SupportPermission::InstallationComplete->value,
                SupportPermission::CalibrationView->value,
                SupportPermission::CalibrationComplete->value,
                SupportPermission::LoanView->value,
                SupportPermission::RmaView->value,
                SupportPermission::QualityComplaintView->value,
            ],
            'Reviewer' => [
                SupportPermission::TicketView->value,
                SupportPermission::SlaPolicyView->value,
                SupportPermission::MaintenanceRequestView->value,
                SupportPermission::ServiceRecordView->value,
                SupportPermission::ReportView->value,
                SupportPermission::AuditView->value,
                SupportPermission::MaintenanceCostView->value,
                SupportPermission::MaintenanceScheduleView->value,
                SupportPermission::WarrantyPolicyView->value,
                SupportPermission::WarrantyRecoveryView->value,
                SupportPermission::TeamView->value,
                SupportPermission::QueueView->value,
                SupportPermission::RoutingRuleView->value,
                SupportPermission::AutomationView->value,
                SupportPermission::ServiceAppointmentView->value,
                SupportPermission::Equipment360View->value,
                SupportPermission::KnowledgeView->value,
                SupportPermission::CsatView->value,
                SupportPermission::SlaCalendarView->value,
                SupportPermission::ServiceLevelView->value,
                SupportPermission::EntitlementView->value,
                SupportPermission::InstallationView->value,
                SupportPermission::CalibrationView->value,
                SupportPermission::LoanView->value,
                SupportPermission::RmaView->value,
                SupportPermission::QualityComplaintView->value,
            ],
        ];
    }
}
