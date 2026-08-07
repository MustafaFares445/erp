<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Brand;
use App\Models\InventoryExport;
use App\Models\InventoryImportRun;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\Unit;
use App\Policies\CatalogPolicy;
use App\Policies\InventoryExportPolicy;
use App\Policies\InventoryImportRunPolicy;
use App\Policies\ShipmentPolicy;
use App\Services\Employees\FakeVoiceNoteTranscriber;
use App\Services\Employees\OpenAiWhisperTranscriber;
use App\Services\Employees\VoiceNoteTranscriber;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
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

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Product::class, CatalogPolicy::class);
        Gate::policy(ProductAttribute::class, CatalogPolicy::class);
        Gate::policy(ProductVariant::class, CatalogPolicy::class);
        Gate::policy(ProductCategory::class, CatalogPolicy::class);
        Gate::policy(Brand::class, CatalogPolicy::class);
        Gate::policy(Supplier::class, CatalogPolicy::class);
        Gate::policy(Unit::class, CatalogPolicy::class);
        Gate::policy(InventoryImportRun::class, InventoryImportRunPolicy::class);
        Gate::policy(InventoryExport::class, InventoryExportPolicy::class);
        Gate::policy(Shipment::class, ShipmentPolicy::class);
    }
}
