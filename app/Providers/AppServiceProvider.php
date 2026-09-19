<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\CampaignCompleted;
use App\Events\InventoryOperationCompleted;
use App\Events\InventoryReservationExpired;
use App\Events\InvoiceIssued;
use App\Events\LeadConverted;
use App\Events\PaymentReceived;
use App\Events\PurchaseOrderAccepted;
use App\Events\QuotationDecided;
use App\Events\QuotationExpired;
use App\Events\SlaAtRisk;
use App\Events\StockLow;
use App\Events\TaskAssigned;
use App\Events\TicketUpdated;
use App\Listeners\MarkShipmentInTransitOnDeliveryCompleted;
use App\Listeners\SendBusinessNotification;
use App\Models\Brand;
use App\Models\InventoryExport;
use App\Models\InventoryImportRun;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Policies\CatalogPolicy;
use App\Policies\InventoryExportPolicy;
use App\Policies\InventoryImportRunPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ProductVariantPolicy;
use App\Policies\PurchaseInboundPolicy;
use App\Policies\ShipmentPolicy;
use App\Policies\SupplierPaymentPolicy;
use App\Policies\SupplierPolicy;
use App\Services\Employees\FakeVoiceNoteTranscriber;
use App\Services\Employees\OpenAiWhisperTranscriber;
use App\Services\Employees\VoiceNoteTranscriber;
use App\Services\Settings\CurrencyCatalogService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class AppServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->bind(
            VoiceNoteTranscriber::class,
            config('employees.transcription.driver') === 'fake'
                ? FakeVoiceNoteTranscriber::class
                : OpenAiWhisperTranscriber::class,
        );
    }

    public function boot(): void
    {
        $this->configureDefaultCurrency();

        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(ProductAttribute::class, CatalogPolicy::class);
        Gate::policy(ProductVariant::class, ProductVariantPolicy::class);
        Gate::policy(PurchaseInbound::class, PurchaseInboundPolicy::class);
        Gate::policy(ProductCategory::class, CatalogPolicy::class);
        Gate::policy(Brand::class, CatalogPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(SupplierPayment::class, SupplierPaymentPolicy::class);
        Gate::policy(Unit::class, CatalogPolicy::class);
        Gate::policy(InventoryImportRun::class, InventoryImportRunPolicy::class);
        Gate::policy(InventoryExport::class, InventoryExportPolicy::class);
        Gate::policy(Shipment::class, ShipmentPolicy::class);

        Event::listen(InventoryOperationCompleted::class, MarkShipmentInTransitOnDeliveryCompleted::class);

        foreach ([
            CampaignCompleted::class,
            InvoiceIssued::class,
            LeadConverted::class,
            PaymentReceived::class,
            PurchaseOrderAccepted::class,
            QuotationDecided::class,
            QuotationExpired::class,
            SlaAtRisk::class,
            StockLow::class,
            TaskAssigned::class,
            TicketUpdated::class,
            InventoryReservationExpired::class,
        ] as $event) {
            Event::listen($event, SendBusinessNotification::class);
        }
    }

    /**
     * Make every Filament table and schema format money in the configured
     * default currency instead of Filament's own 'usd' fallback.
     *
     * The code is resolved lazily and memoised for the lifetime of the
     * closure, because Filament evaluates the default currency once per
     * formatted cell.
     */
    private function configureDefaultCurrency(): void
    {
        $resolve = static function (): string {
            static $code = null;

            if (! is_string($code)) {
                try {
                    $code = app(CurrencyCatalogService::class)->defaultCode();
                } catch (Throwable) {
                    $code = 'AED';
                }
            }

            return $code;
        };

        Table::configureUsing(static function (Table $table) use ($resolve): void {
            $table->defaultCurrency($resolve);
        });

        Schema::configureUsing(static function (Schema $schema) use ($resolve): void {
            $schema->defaultCurrency($resolve);
        });
    }
}
