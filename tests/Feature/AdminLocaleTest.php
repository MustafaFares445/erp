<?php

declare(strict_types=1);

use App\Filament\LocalizedResource;
use App\Filament\Resources\InventoryImportRuns\InventoryImportRunResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Http\Middleware\SetAdminLocale;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

final class AdminLocalePluralLabelResource extends LocalizedResource
{
    protected static ?string $model = Invoice::class;

    #[Override]
    public static function getPluralLabel(): string
    {
        return 'invoices';
    }
}

it('defaults the admin panel to English', function (): void {
    app()->setLocale('ar');

    app(SetAdminLocale::class)->handle(
        Request::create('/admin'),
        static fn (): Response => response('ok'),
    );

    expect(app()->getLocale())->toBe('en');
});

it('applies Arabic locale for the admin panel request', function (): void {
    app()->setLocale('en');

    $request = Request::create('/admin');
    $request->setUserResolver(static fn (): User => User::factory()->make(['locale' => 'ar']));

    $response = app(SetAdminLocale::class)->handle(
        $request,
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
    $this->get(route('admin.locale.switch', 'ar'));

    $this->get(route('filament.admin.auth.login'))
        ->assertOk()
        ->assertSee('lang="ar"', escape: false)
        ->assertSee('dir="rtl"', escape: false);
});

it('switches the admin interface to English and back through the language switcher', function (): void {
    $this->get(route('admin.locale.switch', 'en'))->assertRedirect();

    $this->get(route('filament.admin.auth.login'))
        ->assertOk()
        ->assertSee('lang="en"', escape: false)
        ->assertSee('dir="ltr"', escape: false);

    $this->get(route('admin.locale.switch', 'ar'))->assertRedirect();

    $this->get(route('filament.admin.auth.login'))
        ->assertSee('dir="rtl"', escape: false);
});

it('saves the chosen language on the signed-in user and defaults new users to English', function (): void {
    $user = User::factory()->create();

    expect($user->fresh()->locale)->toBe('en');

    $this->actingAs($user)->get(route('admin.locale.switch', 'ar'))->assertRedirect();

    expect($user->fresh()->locale)->toBe('ar');

    // A fresh session still gets the saved language.
    app('session')->flush();
    app()->setLocale('en');

    $request = Request::create('/admin');
    $request->setUserResolver(static fn (): User => $user->fresh());

    app(SetAdminLocale::class)->handle($request, static fn (): Response => response('ok'));

    expect(app()->getLocale())->toBe('ar');
});

it('rejects unsupported locales', function (): void {
    $this->get(route('admin.locale.switch', 'fr'))->assertNotFound();
});

it('always formats numbers with Western digits, even in Arabic', function (): void {
    app(SetAdminLocale::class)->handle(
        Request::create('/admin'),
        static fn (): Response => response('ok'),
    );

    expect(Number::format(1234567.5, 1))->toBe('1,234,567.5');
});
