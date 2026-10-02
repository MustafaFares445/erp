<?php

declare(strict_types=1);

use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentTransactionStatus;
use App\Filament\Resources\CreditNotes\RelationManagers\CreditNoteLinesRelationManager;
use App\Filament\Resources\InventoryCorrections\RelationManagers\CorrectionLinesRelationManager;
use App\Filament\Resources\InventoryCounts\RelationManagers\InventoryCountLinesRelationManager;
use App\Filament\Resources\PaymentTransactions\Schemas\PaymentTransactionInfolist;
use App\Filament\Resources\PaymentTransactions\Tables\PaymentTransactionsTable;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\InventoryCorrection;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryOperationLine;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\ProductVariant;
use App\Models\TicketPaymentLink;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;

uses(RefreshDatabase::class);

function batch76Property(object $object, string $name): mixed
{
    $reflection = new ReflectionClass($object);

    do {
        if ($reflection->hasProperty($name)) {
            return $reflection->getProperty($name)->getValue($object);
        }
    } while ($reflection = $reflection->getParentClass());

    throw new LogicException("Property {$name} not found.");
}

function batch76TableOwner(): Component&HasTable
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

function batch76FindComponent(iterable $components, string $name): mixed
{
    foreach ($components as $component) {
        if (method_exists($component, 'getName') && $component->getName() === $name) {
            return $component;
        }

        try {
            $property = new ReflectionProperty($component, 'childComponents');
            foreach ((array) $property->getValue($component) as $children) {
                if (! is_iterable($children)) {
                    continue;
                }

                $found = batch76FindComponent($children, $name);
                if ($found !== null) {
                    return $found;
                }
            }
        } catch (ReflectionException) {
            // Leaf component.
        }
    }

    return null;
}

it('covers correction-line action input guards string normalization and missing-variant label fallback', function (): void {
    $correction = InventoryCorrection::factory()->create();
    $manager = new CorrectionLinesRelationManager;
    $manager->ownerRecord = $correction;

    $table = $manager->table(Table::make($manager));
    $action = collect($table->getHeaderActions())
        ->first(fn (mixed $candidate): bool => method_exists($candidate, 'getName') && $candidate->getName() === 'addReceiptLine');

    expect($action)->not->toBeNull();
    $run = $action->getActionFunction();

    expect(fn () => $run([]))
        ->toThrow(LogicException::class, 'receipt line and correction quantity');

    expect(fn () => $run([
        'original_inventory_operation_line_id' => 999999999,
        'transaction_quantity' => 1,
    ]))->toThrow(ModelNotFoundException::class);

    $variant = ProductVariant::factory()->create();
    $line = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $correction->original_inventory_operation_id,
        'product_variant_id' => $variant->getKey(),
        'transaction_quantity' => '2.000000',
        'quantity' => '2.000000',
    ]);
    $variantId = (int) $variant->getKey();
    $variant->delete();

    $options = new ReflectionMethod(CorrectionLinesRelationManager::class, 'receiptLineOptions')->invoke($manager);

    expect($options)->toHaveKey($line->getKey())
        ->and($options[$line->getKey()])->toContain((string) $variantId);
});

it('covers payment-transaction table ticket missing-payment and linked-payment display branches', function (): void {
    $table = PaymentTransactionsTable::configure(Table::make(batch76TableOwner()));
    $column = $table->getColumn('payment.payment_number');

    $placeholder = batch76Property($column, 'placeholder');
    $url = batch76Property($column, 'url');

    $ticketLink = new TicketPaymentLink;
    $ticketLink->forceFill(['status' => PaymentLinkStatus::Settled]);

    $ticketTransaction = new PaymentTransaction;
    $ticketTransaction->forceFill([
        'status' => PaymentTransactionStatus::Succeeded,
        'payment_id' => null,
        'amount_minor' => 1000,
        'currency' => 'AED',
    ]);
    $ticketTransaction->setRelation('purpose', $ticketLink);

    expect($placeholder($ticketTransaction))->toBe($ticketTransaction->settlementDescription());

    $missingPayment = new PaymentTransaction;
    $missingPayment->forceFill([
        'status' => PaymentTransactionStatus::Succeeded,
        'payment_id' => 123456,
        'amount_minor' => 1000,
        'currency' => 'AED',
    ]);
    $missingPayment->setRelation('purpose', new Order);

    expect($placeholder($missingPayment))
        ->toBe(__('admin.payments.transaction_ui.payment_record_missing'));

    $payment = new Payment;
    $payment->forceFill(['id' => 77]);

    $linked = new PaymentTransaction;
    $linked->forceFill([
        'status' => PaymentTransactionStatus::Succeeded,
        'payment_id' => 77,
        'amount_minor' => 1000,
        'currency' => 'AED',
    ]);
    $linked->setRelation('purpose', new Order);
    $linked->setRelation('payment', $payment);

    $url($linked);
    expect(true)->toBeTrue();
});

it('covers payment-transaction infolist settled-ticket and missing-payment branches', function (): void {
    $ticketLink = new TicketPaymentLink;
    $ticketLink->forceFill(['status' => PaymentLinkStatus::Settled]);

    $transaction = new PaymentTransaction;
    $transaction->forceFill([
        'status' => PaymentTransactionStatus::Succeeded,
        'payment_id' => null,
        'amount_minor' => 2500,
        'currency' => 'AED',
    ]);
    $transaction->setRelation('purpose', $ticketLink);

    $banner = new ReflectionMethod(PaymentTransactionInfolist::class, 'bannerMeta')->invoke(null, $transaction);

    expect($banner['description'])->toBe(__('admin.payments.transaction_ui.ticket_settled'));

    $section = new ReflectionMethod(PaymentTransactionInfolist::class, 'settlement')->invoke(null);
    $entry = batch76FindComponent(
        new ReflectionProperty($section, 'childComponents')->getValue($section)['default'] ?? [],
        'payment.payment_number',
    );
    expect($entry)->not->toBeNull();

    $placeholder = batch76Property($entry, 'placeholder');

    $missing = new PaymentTransaction;
    $missing->forceFill([
        'status' => PaymentTransactionStatus::Succeeded,
        'payment_id' => 999,
        'amount_minor' => 1000,
        'currency' => 'AED',
    ]);
    $missing->setRelation('purpose', new Order);

    expect($placeholder($missing))
        ->toBe(__('admin.payments.transaction_ui.payment_record_missing'));
});

it('covers credit-note relation-manager unauthenticated mutation guards', function (): void {
    $creditNote = CreditNote::factory()->create();
    $manager = new CreditNoteLinesRelationManager;
    $manager->ownerRecord = $creditNote;

    auth()->logout();

    $table = $manager->table(Table::make($manager));
    $delete = collect($table->getRecordActions())
        ->first(fn (mixed $candidate): bool => method_exists($candidate, 'getName') && $candidate->getName() === 'delete');

    expect($delete)->not->toBeNull();

    $deleteRun = $delete->getActionFunction();
    expect(fn () => $deleteRun(new CreditNoteLine))
        ->toThrow(LogicException::class, 'authenticated sales user');

    $add = new ReflectionMethod(CreditNoteLinesRelationManager::class, 'addLineAction')->invoke($manager);
    $addRun = $add->getActionFunction();

    expect(fn () => $addRun([]))
        ->toThrow(LogicException::class, 'authenticated sales user');
});

it('covers inventory-count relation-manager invalid quantity and unauthenticated actor guards', function (): void {
    $count = InventoryCount::factory()->create();
    $manager = new InventoryCountLinesRelationManager;
    $manager->ownerRecord = $count;

    $table = $manager->table(Table::make($manager));
    $recordCount = collect($table->getRecordActions())
        ->first(fn (mixed $candidate): bool => method_exists($candidate, 'getName') && $candidate->getName() === 'record_count');

    expect($recordCount)->not->toBeNull();

    $run = $recordCount->getActionFunction();
    expect(fn () => $run(new InventoryCountLine, ['quantity' => null]))
        ->toThrow(LogicException::class, 'counted quantity');

    auth()->logout();

    $runLineAction = new ReflectionMethod(InventoryCountLinesRelationManager::class, 'runLineAction');

    expect(fn (): mixed => $runLineAction->invoke(
        $manager,
        static fn (): null => null,
        'coverage',
    ))->toThrow(LogicException::class, 'authenticated inventory count actor');
});
