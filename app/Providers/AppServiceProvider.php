<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\CampaignCompleted;
use App\Events\EquipmentCalibrationMilestone;
use App\Events\EquipmentInstallationMilestone;
use App\Events\InventoryOperationCompleted;
use App\Events\InventoryReservationExpired;
use App\Events\InvoiceIssued;
use App\Events\LeadConverted;
use App\Events\MaintenanceRecordBilled;
use App\Events\PaymentReceived;
use App\Events\PurchaseOrderAccepted;
use App\Events\PurchaseOrderReceived;
use App\Events\QuotationDecided;
use App\Events\QuotationExpired;
use App\Events\SalesOrderReleased;
use App\Events\ShipmentArrived;
use App\Events\SlaAtRisk;
use App\Events\StockLow;
use App\Events\SupplierCommitmentRecorded;
use App\Events\SupportContinuityMilestone;
use App\Events\TaskAssigned;
use App\Events\TicketClosed;
use App\Events\TicketUpdated;
use App\Listeners\MarkShipmentInTransitOnDeliveryCompleted;
use App\Listeners\RefreshOrderCompletionWindowOnShipmentArrival;
use App\Listeners\SendBusinessNotification;
use App\Listeners\SynchronizeSalesProcurementOnOrderReleased;
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
use App\Services\Accounting\CustomerReceivablesSnapshot;
use App\Services\Accounting\TaxRegisterService;
use App\Services\Employees\FakeVoiceNoteTranscriber;
use App\Services\Employees\OpenAiWhisperTranscriber;
use App\Services\Employees\VoiceNoteTranscriber;
use App\Services\Payments\Providers\FakeStripeClient;
use App\Services\Payments\Providers\StripeApiClient;
use App\Services\Payments\Providers\StripeClientInterface;
use App\Services\Purchasing\PurchaseOrderWorkflowProjectionStore;
use App\Services\Sales\OrderWorkflowProjectionStore;
use App\Services\Sales\SalesDashboardMetricsService;
use App\Services\Settings\BusinessConstraints;
use App\Services\Settings\CurrencyCatalogService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Column;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Filament\Widgets\Widget;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;
use Throwable;

final class AppServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->scoped(OrderWorkflowProjectionStore::class);
        $this->app->scoped(PurchaseOrderWorkflowProjectionStore::class);
        $this->app->scoped(CustomerReceivablesSnapshot::class);
        $this->app->scoped(SalesDashboardMetricsService::class);
        $this->app->scoped(TaxRegisterService::class);
        $this->app->scoped(CurrencyCatalogService::class);

        // Shared for the lifetime of the request so a pricing service, the
        // form that displays the same limit, and a report reading it all see
        // one answer and one round trip.
        $this->app->singleton(BusinessConstraints::class);

        $this->app->bind(
            VoiceNoteTranscriber::class,
            config('employees.transcription.driver') === 'fake'
                ? FakeVoiceNoteTranscriber::class
                : OpenAiWhisperTranscriber::class,
        );

        $secretKey = config('services.stripe.secret_key');

        if (config('services.stripe.enabled') === true && is_string($secretKey) && $secretKey !== '') {
            $this->app->singleton(
                StripeClientInterface::class,
                static fn (): StripeApiClient => new StripeApiClient(new StripeClient($secretKey)),
            );
        } else {
            $this->app->singleton(StripeClientInterface::class, FakeStripeClient::class);
        }
    }

    public function boot(): void
    {
        $this->configureDefaultCurrency();
        $this->configureFilamentLabelTranslations();
        $this->configureTableDefaults();
        $this->configureCustomerApiRateLimits();

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
        Event::listen(ShipmentArrived::class, RefreshOrderCompletionWindowOnShipmentArrival::class);
        Event::listen(SalesOrderReleased::class, SynchronizeSalesProcurementOnOrderReleased::class);

        foreach ([
            CampaignCompleted::class,
            EquipmentCalibrationMilestone::class,
            EquipmentInstallationMilestone::class,
            SupportContinuityMilestone::class,
            InvoiceIssued::class,
            LeadConverted::class,
            MaintenanceRecordBilled::class,
            PaymentReceived::class,
            PurchaseOrderAccepted::class,
            PurchaseOrderReceived::class,
            SupplierCommitmentRecorded::class,
            QuotationDecided::class,
            QuotationExpired::class,
            SlaAtRisk::class,
            StockLow::class,
            TaskAssigned::class,
            TicketClosed::class,
            TicketUpdated::class,
            InventoryReservationExpired::class,
        ] as $event) {
            Event::listen($event, SendBusinessNotification::class);
        }
    }

    /**
     * Route Filament's explicit and generated labels through Laravel's
     * translator. This keeps field names, table columns, filters, actions,
     * sections, tabs, and wizard steps localized without duplicating labels
     * across every resource.
     */
    private function configureFilamentLabelTranslations(): void
    {
        Field::configureUsing(static fn (Field $component): Field => $component->translateLabel());
        Entry::configureUsing(static fn (Entry $component): Entry => $component->translateLabel());
        Column::configureUsing(static fn (Column $component): Column => $component->translateLabel());
        BaseFilter::configureUsing(static fn (BaseFilter $component): BaseFilter => $component->translateLabel());
        Action::configureUsing(static fn (Action $component): Action => $component->translateLabel());
        ActionGroup::configureUsing(static fn (ActionGroup $component): ActionGroup => $component->translateLabel());
        Section::configureUsing(static fn (Section $component): Section => $component->translateLabel());
        Fieldset::configureUsing(static fn (Fieldset $component): Fieldset => $component->translateLabel());
        Tab::configureUsing(static fn (Tab $component): Tab => $component->translateLabel());
        Step::configureUsing(static fn (Step $component): Step => $component->translateLabel());
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
            $table->defaultCurrency($resolve)->defaultNumberLocale('en');
        });

        Schema::configureUsing(static function (Schema $schema) use ($resolve): void {
            $schema->defaultCurrency($resolve)->defaultNumberLocale('en');
        });
    }

    /**
     * Give every resource and relation-manager table the same list
     * experience: a live, reorderable column manager listing every column,
     * filters in a slide-over with an explicit "Apply filters" step, and one set of
     * page-size options.
     *
     * Dashboard table widgets are compact summaries, so they keep their
     * own layout and only lose the column manager the toggleable columns
     * would otherwise switch on.
     */
    private function configureTableDefaults(): void
    {
        // Unlabelled columns (logos, thumbnails) would show as blank rows in the manager.
        Column::configureUsing(static fn (Column $column): Column => $column->toggleable(
            static fn (Column $column): bool => filled($column->getLabel()),
        ));

        Table::configureUsing(static function (Table $table): void {
            if ($table->getLivewire() instanceof Widget) {
                $table->columnManager(false);

                return;
            }

            $table
                // Filament refuses to reorder a table with an unlabelled column.
                ->reorderableColumns(static fn (Table $table): bool => collect($table->getColumns())
                    ->every(static fn (Column $column): bool => filled($column->getLabel())))
                ->deferColumnManager(false)
                ->filtersLayout(FiltersLayout::Modal)
                ->filtersTriggerAction(static fn (Action $action): Action => $action
                    ->slideOver()
                    ->modalWidth(Width::TwoExtraLarge))
                ->deferFilters()
                ->paginationPageOptions([10, 25, 50, 100])
                ->defaultPaginationPageOption(10);
        });
    }

    /**
     * Customer Support API limits: login is throttled per IP and per login+IP so one account cannot be
     * brute-forced from a single address, and every authenticated request has a generous per-user ceiling.
     */
    private function configureCustomerApiRateLimits(): void
    {
        RateLimiter::for('customer-login', static fn (Request $request): array => [
            Limit::perMinute(20)->by('ip|'.$request->ip()),
            Limit::perMinute(5)->by('login|'.$request->string('login')->lower()->toString().'|'.$request->ip()),
        ]);

        RateLimiter::for('customer-api', static fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
