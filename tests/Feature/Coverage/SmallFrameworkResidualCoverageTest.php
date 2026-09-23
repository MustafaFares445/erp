<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Models\User;
use App\Policies\InventoryOperationPolicy;
use App\Providers\AppServiceProvider;
use App\Services\Payments\Providers\StripeApiClient;
use App\Services\Payments\Providers\StripeClientInterface;
use App\Services\Settings\CurrencyCatalogService;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;

uses(RefreshDatabase::class);
function smallFrameworkCoverageTableOwner(): HasTable
{
    return new class extends Component implements HasTable
    {
        use InteractsWithTable;

        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };
}

it('covers delivery-only inventory index authorization', function (): void {
    (new InventoryPermissionSeeder)->run();

    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::DeliveryView->value);

    expect((new InventoryOperationPolicy)->viewInventoryIndex($user))->toBeTrue();
});
it('covers the real Stripe client service-provider binding', function (): void {
    config()->set('services.stripe.enabled', true);
    config()->set('services.stripe.secret_key', 'sk_test_coverage_only');

    $provider = new AppServiceProvider(app());
    $provider->register();

    app()->forgetInstance(StripeClientInterface::class);

    expect(app(StripeClientInterface::class))->toBeInstanceOf(StripeApiClient::class);
});

it('covers the default-currency fallback when the catalog cannot resolve', function (): void {
    app()->instance(CurrencyCatalogService::class, new class
    {
        public function defaultCode(): never
        {
            throw new RuntimeException('Coverage fallback.');
        }
    });

    $provider = new AppServiceProvider(app());
    $configure = new ReflectionMethod(AppServiceProvider::class, 'configureDefaultCurrency');
    $configure->invoke($provider);

    $table = Table::make(smallFrameworkCoverageTableOwner());

    expect($table->getDefaultCurrency())->toBe('AED');
});
