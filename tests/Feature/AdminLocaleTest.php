<?php

declare(strict_types=1);

use App\Filament\LocalizedResource;
use App\Filament\Resources\InventoryImportRuns\InventoryImportRunResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Http\Middleware\SetAdminLocale;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminLocalePluralLabelResource extends LocalizedResource
{
    protected static ?string $model = Invoice::class;

    #[Override]
    public static function getPluralLabel(): string
    {
        return 'invoices';
    }
}

it('forces Arabic locale for the admin panel request', function (): void {
    app()->setLocale('en');

    $response = app(SetAdminLocale::class)->handle(
        Request::create('/admin'),
        static fn (): Response => response('ok'),
    );

    expect(app()->getLocale())->toBe('ar')
        ->and($response->getContent())->toBe('ok')
        ->and(__('filament-panels::layout.direction'))->toBe('rtl')
        ->and(InvoiceResource::getModelLabel())->toBe('فاتورة')
        ->and(InvoiceResource::getPluralModelLabel())->toBe('فواتير')
        ->and(OrderResource::getModelLabel())->toBe('أمر بيع')
        ->and(OrderResource::getPluralModelLabel())->toBe('أوامر البيع')
        ->and(InventoryImportRunResource::getNavigationLabel())->toBe('عمليات استيراد المخزون')
        ->and(AdminLocalePluralLabelResource::getPluralModelLabel())->toBe('فواتير');
});

it('renders the Filament admin login in Arabic RTL', function (): void {
    $this->get(route('filament.admin.auth.login'))
        ->assertOk()
        ->assertSee('lang="ar"', escape: false)
        ->assertSee('dir="rtl"', escape: false);
});
