<?php

declare(strict_types=1);

use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStatus;
use App\Enums\CreditNoteStockConsequence;
use App\Enums\DashboardRole;
use App\Enums\InventoryReturnStatus;
use App\Enums\StockCondition;
use App\Exceptions\Domain\CreditExceedsReturn;
use App\Models\ChartAccount;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\InventoryMovement;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\JournalEntry;
use App\Models\ProductVariant;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Sales\CreditNoteService;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new ChartOfAccountsSeeder)->run();
    (new AccountingPermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();

    $settings = SalesSetting::current();
    $settings->forceFill([
        'receivable_account_id' => ChartAccount::query()->where('code', '1200')->value('id'),
        'revenue_account_id' => ChartAccount::query()->where('code', '4100')->value('id'),
        'deferred_tax_account_id' => ChartAccount::query()->where('code', '2350')->value('id'),
        'tax_payable_account_id' => ChartAccount::query()->where('code', '2300')->value('id'),
    ])->save();

    FiscalPeriod::factory()->create();
});

function creditNoteActor(): User
{
    $actor = User::factory()->admin()->create();
    $actor->assignRole(DashboardRole::SystemAdmin->value);

    return $actor;
}

/** @return array{0: Invoice, 1: InvoiceLine} */
function issuedInvoiceWithLine(CustomerProfile $customer, float $lineTotal = 100.0, float $taxAmount = 0.0): array
{
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'subtotal' => $lineTotal - $taxAmount,
        'tax_total' => $taxAmount,
        'total_amount' => $lineTotal,
        'amount_paid' => 0,
    ]);
    $invoice->forceFill(['issued_at' => now(), 'status' => 'issued'])->save();

    $line = $invoice->lines()->create([
        'description' => 'Widget',
        'quantity' => '2.000',
        'unit_price' => (string) round(($lineTotal - $taxAmount) / 2, 2),
        'tax_amount' => (string) $taxAmount,
        'line_total' => (string) $lineTotal,
        'sort_order' => 1,
    ]);

    return [$invoice->refresh(), $line];
}

/**
 * @return array{0: InventoryReturn, 1: InventoryReturnLine}
 */
function postedCustomerReturnLine(
    CustomerProfile $customer,
    ProductVariant $variant,
    string $quantity = '1.000000',
): array {
    $return = InventoryReturn::factory()->customer()->create([
        'customer_id' => $customer->getKey(),
        'credit_note_required' => true,
    ]);

    $line = $return->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'transaction_quantity' => $quantity,
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => $quantity,
        'source_condition' => StockCondition::Saleable,
        'disposition' => 'saleable',
    ]);

    $return->forceFill([
        'status' => InventoryReturnStatus::Posted,
        'ready_at' => now()->subMinute(),
        'posted_at' => now(),
    ])->save();

    return [$return->refresh(), $line->refresh()];
}

it('confirms a credit note with lines, posts a balanced journal entry, and updates the invoice credited amount', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer, 100.0);

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
        'reason_category' => CreditNoteReason::PricingAdjustment,
    ]);

    app(CreditNoteService::class)->addLine($actor, $creditNote, 'Pricing correction', 1.0, 40.0, 0.0, $invoiceLine);

    $confirmed = app(CreditNoteService::class)->confirm($actor, $creditNote);

    expect($confirmed->status)->toBe(CreditNoteStatus::Confirmed)
        ->and($confirmed->confirmed_at)->not->toBeNull()
        ->and((float) $confirmed->grand_total)->toBe(40.0)
        ->and((float) $invoice->refresh()->credited_amount)->toBe(40.0);

    $entry = JournalEntry::query()->whereMorphedTo('source', $confirmed)->sole();

    expect($entry->isPosted())->toBeTrue()
        ->and((float) $entry->lines()->sum('debit'))->toBe(40.0)
        ->and((float) $entry->lines()->sum('credit'))->toBe(40.0);
});

it('refuses to confirm a credit note with no lines', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    $creditNote = CreditNote::factory()->create(['customer_id' => $customer->getKey()]);

    expect(fn () => app(CreditNoteService::class)->confirm($actor, $creditNote))
        ->toThrow(DomainException::class, 'A credit note requires at least one line.');
});

it('refuses to confirm a credit note whose total exceeds the invoice uncredited balance', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer, 100.0);

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
    ]);

    app(CreditNoteService::class)->addLine($actor, $creditNote, 'Too much', 1.0, 150.0, 0.0, $invoiceLine);

    expect(fn () => app(CreditNoteService::class)->confirm($actor, $creditNote))
        ->toThrow(DomainException::class);
});

it('refuses a credit note line quantity or value beyond the invoice line uncredited remainder', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer, 100.0);

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
    ]);

    app(CreditNoteService::class)->addLine($actor, $creditNote, 'Over quantity', 5.0, 40.0, 0.0, $invoiceLine);

    expect(fn () => app(CreditNoteService::class)->confirm($actor, $creditNote))
        ->toThrow(DomainException::class);
});

it('refuses a credit note customer that does not match its invoice customer', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer, 100.0);

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $otherCustomer->getKey(),
    ]);

    app(CreditNoteService::class)->addLine($actor, $creditNote, 'Mismatch', 1.0, 40.0, 0.0, $invoiceLine);

    expect(fn () => app(CreditNoteService::class)->confirm($actor, $creditNote))
        ->toThrow(DomainException::class, 'The credit note customer must match its invoice.');
});

it('forbids editing, deleting, or adding lines to a confirmed credit note', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer, 100.0);

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    app(CreditNoteService::class)->addLine($actor, $creditNote, 'Line', 1.0, 40.0, 0.0, $invoiceLine);
    $confirmed = app(CreditNoteService::class)->confirm($actor, $creditNote);

    expect(fn () => $confirmed->forceFill(['reason' => 'changed'])->save())
        ->toThrow(DomainException::class)
        ->and(fn () => $confirmed->delete())
        ->toThrow(DomainException::class)
        ->and(fn () => app(CreditNoteService::class)->addLine($actor, $confirmed, 'Another', 1.0, 1.0, 0.0))
        ->toThrow(AuthorizationException::class);
});

it('reverses a confirmed credit note, restoring the invoice credited amount and reversing the posting', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer, 100.0);

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    app(CreditNoteService::class)->addLine($actor, $creditNote, 'Line', 1.0, 40.0, 0.0, $invoiceLine);
    $confirmed = app(CreditNoteService::class)->confirm($actor, $creditNote);

    $reversed = app(CreditNoteService::class)->reverse($actor, $confirmed);

    expect($reversed->status)->toBe(CreditNoteStatus::Reversed)
        ->and($reversed->reversed_at)->not->toBeNull()
        ->and((float) $invoice->refresh()->credited_amount)->toBe(0.0);

    $originalEntry = JournalEntry::query()->whereMorphedTo('source', $reversed)->sole();

    expect($originalEntry->reversal)->not->toBeNull();

    expect(fn () => app(CreditNoteService::class)->reverse($actor, $reversed))
        ->toThrow(AuthorizationException::class);
});

it('requires a separate reverse permission from confirm', function (): void {
    $salesManager = User::factory()->admin()->create();
    $salesManager->assignRole(DashboardRole::SalesManager->value);

    $customer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer, 100.0);

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    app(CreditNoteService::class)->addLine($salesManager, $creditNote, 'Line', 1.0, 40.0, 0.0, $invoiceLine);

    $confirmed = app(CreditNoteService::class)->confirm($salesManager, $creditNote);

    expect(fn () => app(CreditNoteService::class)->reverse($salesManager, $confirmed))
        ->toThrow(AuthorizationException::class);
});

it('removes a draft line but refuses to remove a line from a confirmed credit note', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer, 100.0);

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    $line = app(CreditNoteService::class)->addLine($actor, $creditNote, 'Line', 1.0, 40.0, 0.0, $invoiceLine);

    app(CreditNoteService::class)->removeLine($actor, $line);

    expect($creditNote->lines()->count())->toBe(0);

    app(CreditNoteService::class)->addLine($actor, $creditNote, 'Line', 1.0, 40.0, 0.0, $invoiceLine);
    $confirmed = app(CreditNoteService::class)->confirm($actor, $creditNote);
    $confirmedLine = $confirmed->lines()->sole();

    expect(fn () => app(CreditNoteService::class)->removeLine($actor, $confirmedLine))
        ->toThrow(AuthorizationException::class);
});

it('credits exactly the quantity supported by a linked posted return line', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();

    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'subtotal' => 100,
        'tax_total' => 0,
        'total_amount' => 100,
        'amount_paid' => 0,
    ]);
    $invoice->forceFill(['issued_at' => now(), 'status' => 'issued'])->save();
    $invoiceLine = $invoice->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'description' => 'Returned item',
        'quantity' => '2.000',
        'unit_price' => '50.00',
        'tax_amount' => '0.00',
        'line_total' => '100.00',
        'sort_order' => 1,
    ]);

    [$return, $returnLine] = postedCustomerReturnLine($customer, $variant, '1.000000');

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'inventory_return_id' => $return->getKey(),
        'customer_id' => $customer->getKey(),
        'reason_category' => CreditNoteReason::SalesReturn,
        'stock_consequence' => CreditNoteStockConsequence::GoodsReturned,
    ]);

    app(CreditNoteService::class)->addLine(
        $actor,
        $creditNote,
        'Returned item',
        1.0,
        50.0,
        0.0,
        $invoiceLine,
        $returnLine,
    );

    $confirmed = app(CreditNoteService::class)->confirm($actor, $creditNote);

    expect($confirmed->isConfirmed())->toBeTrue()
        ->and($confirmed->inventory_return_id)->toBe($return->getKey())
        ->and(app(CreditNoteService::class)->creditedQuantityForReturnLine($returnLine))->toBe('1.000000')
        ->and((float) $invoice->fresh()->credited_amount)->toBe(50.0);
});

it('rejects credit quantity above the linked returned quantity', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer, 100.0);
    $invoiceLine->forceFill(['product_variant_id' => $variant->getKey()])->saveQuietly();
    [$return, $returnLine] = postedCustomerReturnLine($customer, $variant, '1.000000');

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'inventory_return_id' => $return->getKey(),
        'customer_id' => $customer->getKey(),
        'reason_category' => CreditNoteReason::SalesReturn,
        'stock_consequence' => CreditNoteStockConsequence::GoodsReturned,
    ]);

    expect(fn () => app(CreditNoteService::class)->addLine(
        $actor,
        $creditNote,
        'Too much return credit',
        1.001,
        40.0,
        0.0,
        $invoiceLine,
        $returnLine,
    ))->toThrow(CreditExceedsReturn::class);

    expect($creditNote->lines()->count())->toBe(0);
});

it('caps multiple confirmed credit notes at the remaining returned quantity', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer, 200.0);
    $invoiceLine->forceFill([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '4.000',
    ])->saveQuietly();
    [$return, $returnLine] = postedCustomerReturnLine($customer, $variant, '2.000000');

    foreach ([1, 2] as $sequence) {
        $note = CreditNote::factory()->create([
            'invoice_id' => $invoice->getKey(),
            'inventory_return_id' => $return->getKey(),
            'customer_id' => $customer->getKey(),
            'reason_category' => CreditNoteReason::SalesReturn,
            'stock_consequence' => CreditNoteStockConsequence::GoodsReturned,
        ]);
        app(CreditNoteService::class)->addLine(
            $actor,
            $note,
            'Return credit '.$sequence,
            1.0,
            25.0,
            0.0,
            $invoiceLine,
            $returnLine,
        );
        app(CreditNoteService::class)->confirm($actor, $note);
    }

    expect(app(CreditNoteService::class)->creditedQuantityForReturnLine($returnLine))->toBe('2.000000');

    $third = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'inventory_return_id' => $return->getKey(),
        'customer_id' => $customer->getKey(),
        'reason_category' => CreditNoteReason::SalesReturn,
        'stock_consequence' => CreditNoteStockConsequence::GoodsReturned,
    ]);

    expect(fn () => app(CreditNoteService::class)->addLine(
        $actor,
        $third,
        'Third credit',
        0.001,
        1.0,
        0.0,
        $invoiceLine,
        $returnLine,
    ))->toThrow(CreditExceedsReturn::class);
});

it('requires explicit stock consequence for a sales-return credit', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer);

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
        'reason_category' => CreditNoteReason::SalesReturn,
        'stock_consequence' => CreditNoteStockConsequence::NotApplicable,
    ]);
    app(CreditNoteService::class)->addLine($actor, $creditNote, 'Return', 1.0, 40.0, 0.0, $invoiceLine);

    expect(fn () => app(CreditNoteService::class)->confirm($actor, $creditNote))
        ->toThrow(DomainException::class, 'must state whether goods were returned or retained');

    expect($creditNote->fresh()->isDraft())->toBeTrue();
});

it('allows a customer-retained sales-return credit without writing inventory movements', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer);

    $beforeMovements = InventoryMovement::query()->count();

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
        'reason_category' => CreditNoteReason::SalesReturn,
        'stock_consequence' => CreditNoteStockConsequence::CustomerRetained,
    ]);
    app(CreditNoteService::class)->addLine($actor, $creditNote, 'Customer retained item', 1.0, 40.0, 0.0, $invoiceLine);

    $confirmed = app(CreditNoteService::class)->confirm($actor, $creditNote);

    expect($confirmed->isConfirmed())->toBeTrue()
        ->and($confirmed->inventory_return_id)->toBeNull()
        ->and(InventoryMovement::query()->count())->toBe($beforeMovements);
});

it('rejects a linked return that belongs to a different customer', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer);
    $invoiceLine->forceFill(['product_variant_id' => $variant->getKey()])->saveQuietly();
    [$return, $returnLine] = postedCustomerReturnLine($otherCustomer, $variant);

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'inventory_return_id' => $return->getKey(),
        'customer_id' => $customer->getKey(),
        'reason_category' => CreditNoteReason::SalesReturn,
        'stock_consequence' => CreditNoteStockConsequence::GoodsReturned,
    ]);

    app(CreditNoteService::class)->addLine(
        $actor,
        $creditNote,
        'Cross customer return',
        1.0,
        40.0,
        0.0,
        $invoiceLine,
        $returnLine,
    );

    expect(fn () => app(CreditNoteService::class)->confirm($actor, $creditNote))
        ->toThrow(DomainException::class, 'posted return for the same customer');
});

it('rejects goods-returned consequence without a posted return link', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer);

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
        'reason_category' => CreditNoteReason::SalesReturn,
        'stock_consequence' => CreditNoteStockConsequence::GoodsReturned,
        'inventory_return_id' => null,
    ]);
    app(CreditNoteService::class)->addLine(
        $actor,
        $creditNote,
        'Unlinked returned goods',
        1.0,
        40.0,
        0.0,
        $invoiceLine,
    );

    expect(fn () => app(CreditNoteService::class)->confirm($actor, $creditNote))
        ->toThrow(DomainException::class, 'posted return for the same customer');

    expect($creditNote->fresh()->isDraft())->toBeTrue();
});

it('rejects a customer-retained consequence that also links an inventory return', function (): void {
    $actor = creditNoteActor();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();
    [$invoice, $invoiceLine] = issuedInvoiceWithLine($customer);
    $invoiceLine->forceFill(['product_variant_id' => $variant->getKey()])->saveQuietly();
    [$return] = postedCustomerReturnLine($customer, $variant);

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'inventory_return_id' => $return->getKey(),
        'customer_id' => $customer->getKey(),
        'reason_category' => CreditNoteReason::SalesReturn,
        'stock_consequence' => CreditNoteStockConsequence::CustomerRetained,
    ]);
    app(CreditNoteService::class)->addLine(
        $actor,
        $creditNote,
        'Contradictory consequence',
        1.0,
        40.0,
        0.0,
        $invoiceLine,
    );

    expect(fn () => app(CreditNoteService::class)->confirm($actor, $creditNote))
        ->toThrow(DomainException::class, 'cannot link to an inventory return');
});
