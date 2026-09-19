<?php

declare(strict_types=1);

use App\Filament\Resources\AccountsReceivable\Pages\ListAccountsReceivable;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function receivableCoveragePage(): ListAccountsReceivable
{
    return new ReflectionClass(ListAccountsReceivable::class)->newInstanceWithoutConstructor();
}

it('loads receivable summaries and customer detail through page state changes', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create(['company_name' => 'AR Coverage Customer']);
    $this->actingAs($admin);
    $page = receivableCoveragePage();

    $page->mount();
    expect($page->asOf)->not->toBeNull()
        ->and($page->summary)->not->toBeEmpty()
        ->and($page->reconciliation)->not->toBeEmpty()
        ->and($page->detail)->toBe([]);

    $page->showCustomer((int) $customer->getKey());
    expect($page->customerId)->toBe($customer->getKey())
        ->and($page->selectedCustomerName)->toBe('AR Coverage Customer')
        ->and($page->detail)->not->toBeEmpty();

    $page->updatedAsOf();
    $page->clearCustomer();
    expect($page->customerId)->toBeNull()
        ->and($page->selectedCustomerName)->toBeNull();

    $page->showCustomer(999999);
    expect($page->customerId)->toBeNull();
    expect($page->getTitle())->toBeString()->not->toBeEmpty();
});

it('guards customer statement downloads and streams a valid empty statement', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create(['company_name' => 'Statement Coverage']);
    $this->actingAs($admin);
    $page = receivableCoveragePage();
    $page->asOf = today()->toDateString();

    expect(fn (): StreamedResponse => $page->downloadStatement())
        ->toThrow(LogicException::class, 'Choose a customer before downloading a statement.');

    $page->customerId = 999999;
    expect(fn (): StreamedResponse => $page->downloadStatement())
        ->toThrow(LogicException::class, 'The selected customer no longer exists.');
    Invoice::factory()->for($customer, 'customer')->create([
        'issued_at' => now()->subDay(),
        'invoice_date' => today()->subDay(),
        'due_date' => today()->addDays(29),
        'total_amount' => '120.00',
        'subtotal' => '120.00',
        'tax_total' => '0.00',
    ]);

    $page->customerId = (int) $customer->getKey();
    $response = $page->downloadStatement();

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('content-type'))->toContain('text/csv')
        ->and($csv)->toBeString()->toContain('invoice');
});

it('streams the aging export header action and denies unauthenticated report access', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $page = receivableCoveragePage();
    $page->asOf = today()->toDateString();

    $method = new ReflectionMethod(ListAccountsReceivable::class, 'getHeaderActions');
    $actions = $method->invoke($page);
    $response = $actions[0]->call();

    expect($response)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toBeString()->toContain('Customer');

    auth()->logout();
    $unauthenticated = receivableCoveragePage();

    expect(fn () => $unauthenticated->mount())
        ->toThrow(HttpException::class);
});
