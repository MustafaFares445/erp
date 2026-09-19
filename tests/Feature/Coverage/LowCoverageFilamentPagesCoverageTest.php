<?php

declare(strict_types=1);

use App\Data\Crm\LeadData;
use App\Enums\LeadSource;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\SalesOpportunities\Pages\CreateSalesOpportunity;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Crm\LeadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);
function invokeCoveragePageMethod(object $instance, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($instance, $method);

    return $reflection->invokeArgs($instance, $arguments);
}

function makeCoveragePage(string $class): object
{
    return new ReflectionClass($class)->newInstanceWithoutConstructor();
}

it('covers lead edit page validation and update paths', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $lead = app(LeadService::class)->create(new LeadData(
        source: LeadSource::Website,
        firstName: 'Before',
        email: 'before@example.test',
    ), $actor);

    $page = makeCoveragePage(EditLead::class);
    expect(fn (): mixed => invokeCoveragePageMethod($page, 'handleRecordUpdate', [new class extends Model {}, []]))
        ->toThrow(LogicException::class, 'Expected a lead record.');
    expect(fn (): mixed => invokeCoveragePageMethod($page, 'handleRecordUpdate', [$lead, ['source' => '']]))
        ->toThrow(LogicException::class);

    $updated = invokeCoveragePageMethod($page, 'handleRecordUpdate', [$lead, [
        'source' => LeadSource::Website->value,
        'first_name' => 'After',
        'email' => 'after@example.test',
        'preferred_language' => 'en',
    ]]);

    expect($updated->first_name)->toBe('After')
        ->and($updated->email)->toBe('after@example.test');

    auth()->logout();
    expect(fn (): mixed => invokeCoveragePageMethod($page, 'handleRecordUpdate', [$updated, [
        'source' => LeadSource::Website->value,
    ]]))->toThrow(LogicException::class, 'An authenticated CRM user is required.');
});

it('covers expense edit normalization and update paths', function (): void {
    $page = makeCoveragePage(EditExpense::class);
    $expense = Expense::factory()->create();
    expect(invokeCoveragePageMethod($page, 'normalizeData', [null]))->toBe([])
        ->and(invokeCoveragePageMethod($page, 'normalizeData', [[1 => 'one', 'x' => 2]]))
        ->toBe(['1' => 'one', 'x' => 2]);

    $updated = invokeCoveragePageMethod($page, 'handleRecordUpdate', [$expense, [
        'description' => 'Coverage update',
        'receipt' => null,
    ]]);

    expect($updated)->toBe($expense)
        ->and($expense->refresh()->description)->toBe('Coverage update');
});

it('covers sales opportunity create page guards and valid creation', function (): void {
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $this->actingAs($actor);
    $page = makeCoveragePage(CreateSalesOpportunity::class);

    expect(fn (): mixed => invokeCoveragePageMethod($page, 'handleRecordCreation', [[]]))
        ->toThrow(LogicException::class, 'An opportunity summary is required.');

    $created = invokeCoveragePageMethod($page, 'handleRecordCreation', [[
        'summary' => 'Coverage opportunity',
        'customer_id' => (string) $customer->getKey(),
        'estimated_value_minor' => '1000',
        'probability_percent' => '25',
        'currency' => 'AED',
    ]]);
    expect($created->customer_id)->toBe($customer->getKey())
        ->and($created->summary)->toBe('Coverage opportunity')
        ->and($created->estimated_value_minor)->toBe(1000);

    auth()->logout();
    expect(fn (): mixed => invokeCoveragePageMethod($page, 'handleRecordCreation', [[
        'summary' => 'No actor',
        'customer_id' => $customer->getKey(),
    ]]))->toThrow(LogicException::class, 'Authenticated user required.');
});

it('covers sales list-page export metadata and row formatting', function (): void {
    $customer = CustomerProfile::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $payment = new Payment;
    $payment->forceFill([
        'payment_number' => 'PAY-COV-1',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $paymentMethod->getKey(),
        'payment_date' => today(),
        'amount' => '125.50',
        'status' => 'draft',
    ]);
    $payment->setRelation('customer', $customer);
    $payment->setRelation('paymentMethod', $paymentMethod);

    $cases = [
        [ListPayments::class, $payment, 'payments-', 'sales.payment.exported'],
        [ListOrders::class, Order::factory()->for($customer, 'customer')->create(), 'orders-', 'sales.order.exported'],
        [ListCreditNotes::class, CreditNote::factory()->for($customer, 'customer')->create(), 'credit-notes-', 'sales.credit_note.exported'],
        [ListQuotations::class, Quotation::factory()->for($customer, 'customer')->create(), 'quotations-', 'sales.quotation.exported'],
    ];

    foreach ($cases as [$class, $record, $filenamePrefix, $logName]) {
        $page = makeCoveragePage($class);
        $headings = invokeCoveragePageMethod($page, 'salesDocumentExportHeadings');
        $row = invokeCoveragePageMethod($page, 'salesDocumentExportRow', [$record]);
        $empty = invokeCoveragePageMethod($page, 'salesDocumentExportRow', [new class extends Model {}]);
        $filename = invokeCoveragePageMethod($page, 'salesDocumentExportFilename');
        $actualLogName = invokeCoveragePageMethod($page, 'salesDocumentExportLogName');

        expect($headings)->not->toBeEmpty()
            ->and($row)->not->toBeEmpty()
            ->and($empty)->toBe([])
            ->and($filename)->toStartWith($filenamePrefix)
            ->and($filename)->toEndWith('.csv')
            ->and($actualLogName)->toBe($logName);
    }
});
