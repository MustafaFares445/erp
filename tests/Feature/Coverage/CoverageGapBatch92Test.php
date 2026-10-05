<?php

declare(strict_types=1);

use App\Enums\ExpenseStatus;
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Resources\AccountsPayable\Pages\ListAccountsPayable;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Search\IerpGlobalSearchProvider;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountsPayableService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('covers accounts-payable statement validation paid entries and page download branches', function (): void {
    Gate::before(static fn (): bool => true);

    $supplier = Supplier::factory()->create(['name' => 'Coverage AP Supplier']);
    Expense::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => ExpenseStatus::Approved,
        'expense_date' => '2026-01-10',
        'due_date' => '2026-01-20',
        'total_amount' => '100.00',
        'amount_paid' => '25.00',
        'payment_date' => '2026-01-15',
    ]);

    $service = app(AccountsPayableService::class);

    expect(fn () => $service->statement(
        $supplier,
        CarbonImmutable::parse('2026-02-01'),
        CarbonImmutable::parse('2026-01-01'),
    ))->toThrow(LogicException::class, 'end date');

    $statement = $service->statement(
        $supplier,
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-01-31'),
    );

    expect(collect($statement['entries'])->pluck('type'))
        ->toContain('expense', 'expense_payment');

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $page = new ReflectionClass(ListAccountsPayable::class)->newInstanceWithoutConstructor();
    $page->asOf = '2026-01-31';
    $page->supplierId = $supplier->id;

    expect($page->documentUrl('bill', 123))->toContain('/bills/123')
        ->and($page->documentUrl('expense', 456))->toContain('/expenses/456')
        ->and($page->documentUrl('unknown', 1))->toBeNull();

    $response = $page->downloadStatement();
    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain('Coverage AP Supplier', 'expense_payment', 'Carried forward');

    $page->supplierId = null;
    expect(fn () => $page->downloadStatement())
        ->toThrow(LogicException::class, 'Choose a supplier');

    $page->supplierId = 999999999;
    expect(fn () => $page->downloadStatement())
        ->toThrow(LogicException::class, 'no longer exists');
});

it('covers accounts-payable report loading and supplier clear/missing selection behavior', function (): void {
    Gate::before(static fn (): bool => true);

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $supplier = Supplier::factory()->create(['name' => 'Coverage Detail Supplier']);
    Expense::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => ExpenseStatus::Approved,
        'expense_date' => today()->subDays(10),
        'due_date' => today()->subDay(),
        'total_amount' => '40.00',
        'amount_paid' => '0.00',
    ]);

    $page = new ReflectionClass(ListAccountsPayable::class)->newInstanceWithoutConstructor();
    $page->asOf = today()->toDateString();
    $page->supplierId = null;
    $page->summary = [];
    $page->detail = [];
    $page->selectedSupplierName = null;

    $load = new ReflectionMethod(ListAccountsPayable::class, 'loadReport');
    $load->invoke($page);
    expect($page->summary)->not->toBeEmpty()
        ->and($page->detail)->toBe([])
        ->and($page->selectedSupplierName)->toBeNull();

    $page->showSupplier($supplier->id);
    expect($page->supplierId)->toBe($supplier->id)
        ->and($page->selectedSupplierName)->toBe('Coverage Detail Supplier')
        ->and($page->detail)->not->toBeEmpty();

    $page->clearSupplier();
    expect($page->supplierId)->toBeNull()
        ->and($page->detail)->toBe([]);

    $page->supplierId = 999999999;
    $load->invoke($page);
    expect($page->supplierId)->toBeNull();
});

it('covers global-search navigation registry URL page resource and invalid-link branches', function (): void {
    Gate::before(static fn (): bool => true);

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $provider = app(IerpGlobalSearchProvider::class);
    $navigation = new ReflectionMethod(IerpGlobalSearchProvider::class, 'navigationResults');
    $urlFor = new ReflectionMethod(IerpGlobalSearchProvider::class, 'urlForRegistryItem');
    $categoryFor = new ReflectionMethod(IerpGlobalSearchProvider::class, 'categoryFor');

    expect($navigation->invoke($provider, ''))->toBe([]);

    $purchaseNavigation = $navigation->invoke($provider, 'purchase');
    expect($purchaseNavigation)->not->toBeEmpty();

    expect($urlFor->invoke($provider, [
        'label' => 'Bills',
        'link' => BillResource::class,
        'page' => 'index',
    ]))->toContain('/bills');

    expect($urlFor->invoke($provider, [
        'label' => 'Purchase needs',
        'link' => PurchaseNeeds::class,
    ]))->toContain('purchase-needs');

    expect($urlFor->invoke($provider, [
        'label' => 'Invalid',
        'link' => stdClass::class,
    ]))->toBeNull();

    expect($categoryFor->invoke($provider, ExpenseResource::class))->not->toBe('')
        ->and($categoryFor->invoke($provider, stdClass::class))->toBe(__('Other'));

    $results = $provider->getResults('purchase');
    expect($results)->not->toBeNull();
});
