<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Resources\InventorySettings\InventorySettingResource;
use App\Filament\Resources\NotificationPreferences\NotificationPreferenceResource;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Resources\PurchaseSettings\PurchaseSettingResource;
use App\Filament\Resources\SalesSettings\SalesSettingResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;

/**
 * A settings hub (WP-3.7, GAP-UI-07, MD-08) — navigation over the settings
 * pages that already exist and were previously scattered across modules,
 * not new behaviour of its own. Each card is filtered by whether the
 * viewer's own permissions let them open that resource, same as any other
 * navigation item — hiding a card is a convenience, not the authorisation
 * (each linked resource still gates itself).
 */
final class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected string $view = 'filament.pages.settings';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.settings');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.settings');
    }

    /**
     * @return list<array{label: string, description: string, url: string, icon: Heroicon}>
     */
    public function cards(): array
    {
        $cards = [
            [
                'resource' => SalesSettingResource::class,
                'label' => __('admin.resources.sales_settings'),
                'description' => 'Default tax rate, quotation validity, and the sales control accounts.',
                'icon' => Heroicon::OutlinedShoppingCart,
            ],
            [
                'resource' => PurchaseSettingResource::class,
                'label' => __('admin.resources.purchase_settings'),
                'description' => 'Purchasing approval thresholds and defaults.',
                'icon' => Heroicon::OutlinedTruck,
            ],
            [
                'resource' => InventorySettingResource::class,
                'label' => __('admin.resources.inventory_settings'),
                'description' => 'Reorder, reservation, and lot expiry defaults.',
                'icon' => Heroicon::OutlinedCube,
            ],
            [
                'resource' => DocumentTemplateResource::class,
                'label' => __('admin.resources.document_templates'),
                'description' => 'The subject and body content of invoice documents, per locale.',
                'icon' => Heroicon::OutlinedDocumentDuplicate,
            ],
            [
                'resource' => NotificationTemplateResource::class,
                'label' => __('admin.resources.notification_templates'),
                'description' => 'The content of every system notification, per channel and locale.',
                'icon' => Heroicon::OutlinedBellAlert,
            ],
            [
                'resource' => NotificationPreferenceResource::class,
                'label' => __('admin.resources.notification_preferences'),
                'description' => 'Default notification channels and opt-outs.',
                'icon' => Heroicon::OutlinedAdjustmentsHorizontal,
            ],
        ];

        $visible = [];

        foreach ($cards as $card) {
            $resource = $card['resource'];

            if (! $resource::canAccess()) {
                continue;
            }

            $visible[] = [
                'label' => $card['label'],
                'description' => $card['description'],
                'url' => $resource::getUrl(),
                'icon' => $card['icon'],
            ];
        }

        return $visible;
    }
}
