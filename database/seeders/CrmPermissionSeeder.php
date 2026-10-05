<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CrmPermission;
use App\Enums\InventoryPermission;
use App\Models\User;
use Database\Seeders\Concerns\SeedsPermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class CrmPermissionSeeder extends Seeder
{
    use SeedsPermissionCatalog;

    public function run(): void
    {
        Permission::query()->whereIn('name', [
            'crm.subscription.view',
            'crm.subscription.manage',
            'crm.subscription.discount.manage',
            'crm.subscription.link.manage',
            'crm.subscription.restore',
        ])->delete();

        $this->seedPermissionCatalog($this->permissions());

        foreach ($this->rolePermissions() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permissions);
        }

        $administrator = User::query()->where('email', 'admin@ierp.com')->first();
        if ($administrator instanceof User) {
            $administrator->assignRole('System Admin');
        }
    }

    /** @return list<string> */
    private function permissions(): array
    {
        return [...CrmPermission::values(), InventoryPermission::PriceFloorApprove->value];
    }

    /** @return array<string, list<string>> */
    private function rolePermissions(): array
    {
        return [
            'System Admin' => $this->permissions(),
            'CRM Manager' => [
                CrmPermission::CustomerView->value,
                CrmPermission::CustomerManage->value,
                CrmPermission::LeadView->value,
                CrmPermission::LeadCreate->value,
                CrmPermission::LeadUpdate->value,
                CrmPermission::LeadAssign->value,
                CrmPermission::LeadConvert->value,
                CrmPermission::InteractionView->value,
                CrmPermission::InteractionCreate->value,
                CrmPermission::CampaignView->value,
                CrmPermission::CampaignManage->value,
                CrmPermission::CampaignSend->value,
                CrmPermission::FunnelReport->value,
                CrmPermission::PricingTierView->value,
                CrmPermission::PricingTierManage->value,
                CrmPermission::PricingTierDiscountManage->value,
                CrmPermission::PricingTierLinkManage->value,
                CrmPermission::PricePreview->value,
                CrmPermission::ReportView->value,
                CrmPermission::AuditView->value,
                CrmPermission::QuotationRequestView->value,
                CrmPermission::QuotationRequestManage->value,
                CrmPermission::ReturnRequestView->value,
                CrmPermission::ReturnRequestManage->value,
            ],
            'Pricing Manager' => [
                CrmPermission::CustomerView->value,
                CrmPermission::PricingTierView->value,
                CrmPermission::PricingTierDiscountManage->value,
                CrmPermission::PricePreview->value,
                CrmPermission::ReportView->value,
                CrmPermission::AuditView->value,
            ],
            'Reviewer' => [
                CrmPermission::CustomerView->value,
                CrmPermission::LeadView->value,
                CrmPermission::InteractionView->value,
                CrmPermission::CampaignView->value,
                CrmPermission::FunnelReport->value,
                CrmPermission::PricingTierView->value,
                CrmPermission::PricePreview->value,
                CrmPermission::ReportView->value,
                CrmPermission::AuditView->value,
                CrmPermission::QuotationRequestView->value,
                CrmPermission::ReturnRequestView->value,
            ],
        ];
    }
}
