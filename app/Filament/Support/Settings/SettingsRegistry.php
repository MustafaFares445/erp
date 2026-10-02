<?php

declare(strict_types=1);

namespace App\Filament\Support\Settings;

use App\Filament\Pages\CatalogSetup;
use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Resources\InventorySettings\InventorySettingResource;
use App\Filament\Resources\NotificationPreferences\NotificationPreferenceResource;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Resources\PackageTypes\PackageTypeResource;
use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Resources\PaymentTerms\PaymentTermResource;
use App\Filament\Resources\PurchaseSettings\PurchaseSettingResource;
use App\Filament\Resources\SalesSettings\SalesSettingResource;
use App\Filament\Resources\SlaPolicies\SlaPolicyResource;
use App\Filament\Resources\Taxes\TaxResource;
use App\Filament\Resources\WarrantyPolicies\WarrantyPolicyResource;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Throwable;

final class SettingsRegistry
{
    /** @return list<array{group:string,label:string,description:string,keywords:string,url:string,icon:Heroicon}> */
    public static function accessible(): array
    {
        $resolved = [];

        foreach (self::definitions() as $definition) {
            $component = $definition['component'];

            try {
                if (! $component::canAccess()) {
                    continue;
                }

                $url = is_subclass_of($component, Resource::class)
                    ? $component::getUrl('index')
                    : $component::getUrl();
            } catch (Throwable) {
                continue;
            }

            $resolved[] = [
                'group' => $definition['group'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'keywords' => $definition['keywords'],
                'url' => $url,
                'icon' => $definition['icon'],
            ];
        }

        return $resolved;
    }

    /** @return list<array{component: class-string<resource>|class-string<Page>, group: string, label: string, description: string, keywords: string, icon: Heroicon}> */
    private static function definitions(): array
    {
        return [
            self::item(CurrencyResource::class, 'Commercial', __('Currencies'), 'Active and default transaction currencies.', 'currency iso exchange commercial', Heroicon::OutlinedCurrencyDollar),
            self::item(PaymentTermResource::class, 'Commercial', __('Payment terms'), 'Customer and supplier due-date terms.', 'payment term due credit commercial', Heroicon::OutlinedCalendarDays),
            self::item(SalesSettingResource::class, 'Commercial', __('admin.resources.sales_settings'), 'Quotation validity, sales defaults, and control accounts.', 'sales quotation invoice defaults accounts', Heroicon::OutlinedShoppingCart),

            self::item(PurchaseSettingResource::class, 'Purchasing', __('admin.resources.purchase_settings'), 'Approval thresholds and purchasing defaults.', 'purchase po approval threshold vendor', Heroicon::OutlinedTruck),

            self::item(InventorySettingResource::class, 'Inventory', __('admin.resources.inventory_settings'), 'Reorder, reservation, lot, and expiry defaults.', 'inventory stock reorder reservation lot expiry', Heroicon::OutlinedCube),
            self::item(PackageTypeResource::class, 'Inventory', __('admin.resources.package_types'), 'Package/container definitions used by warehouse operations.', 'package type warehouse logistics', Heroicon::OutlinedArchiveBox),
            self::item(CatalogSetup::class, 'Inventory', __('admin.resources.catalog_setup'), 'Catalog configuration, units, categories, brands and product setup.', 'catalog product unit category brand', Heroicon::OutlinedWrenchScrewdriver),

            self::item(TaxResource::class, 'Accounting & Tax', __('admin.resources.taxes'), 'Tax definitions and accounting mappings.', 'tax vat accounting ledger', Heroicon::OutlinedReceiptPercent),
            self::item(PaymentMethodResource::class, 'Payments', __('admin.resources.payment_methods'), 'Payment channels and collection-account mappings.', 'payment stripe bank cash collection account', Heroicon::OutlinedCreditCard),

            self::item(SlaPolicyResource::class, 'Support & Warranty', __('admin.resources.sla_policies'), 'Response and resolution service-level rules.', 'support sla response resolution', Heroicon::OutlinedClock),
            self::item(WarrantyPolicyResource::class, 'Support & Warranty', __('admin.resources.warranty_policies'), 'Warranty eligibility and coverage policy setup.', 'warranty support coverage claim', Heroicon::OutlinedShieldCheck),

            self::item(DocumentTemplateResource::class, 'Notifications & Documents', __('admin.resources.document_templates'), 'Document subject/body content by locale.', 'document invoice template pdf locale', Heroicon::OutlinedDocumentDuplicate),
            self::item(NotificationTemplateResource::class, 'Notifications & Documents', __('admin.resources.notification_templates'), 'System notification content by channel and locale.', 'notification email database template channel', Heroicon::OutlinedBellAlert),
            self::item(NotificationPreferenceResource::class, 'Notifications & Documents', __('admin.resources.notification_preferences'), 'Default channels and notification opt-outs.', 'notification preference channel opt out', Heroicon::OutlinedAdjustmentsHorizontal),
        ];
    }

    /**
     * @param  class-string<resource>|class-string<Page>  $component
     * @return array{component: class-string<resource>|class-string<Page>, group: string, label: string, description: string, keywords: string, icon: Heroicon}
     */
    private static function item(
        string $component,
        string $group,
        string $label,
        string $description,
        string $keywords,
        Heroicon $icon,
    ): array {
        return ['component' => $component, 'group' => $group, 'label' => $label, 'description' => $description, 'keywords' => $keywords, 'icon' => $icon];
    }
}
