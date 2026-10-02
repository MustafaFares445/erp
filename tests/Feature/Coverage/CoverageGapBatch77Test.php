<?php

declare(strict_types=1);

use App\Enums\SupplierConfirmationStatus;
use App\Filament\Concerns\InteractsWithSalesServices;
use App\Filament\Resources\Customers\RelationManagers\CustomerInteractionsRelationManager;
use App\Filament\Resources\InventoryCounts\Pages\CreateInventoryCount;
use App\Filament\Resources\InventoryCounts\RelationManagers\InventoryCountLinesRelationManager;
use App\Filament\Resources\JournalEntries\Schemas\JournalEntryLinesRepeater;
use App\Filament\Resources\PurchaseOrders\RelationManagers\ConfirmationsRelationManager;
use App\Filament\Resources\Quotations\Tables\QuotationsTable;
use App\Filament\Resources\SalesOpportunities\Schemas\SalesOpportunityInfolist;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Filament\Resources\SupplierProductSupports\SupplierProductSupportResource;
use App\Models\CustomerProfile;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\SalesOpportunity;
use App\Models\SupplierProductSupport;
use App\Models\VoiceNoteTranscription;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;

uses(RefreshDatabase::class);

function batch77Property(object $object, string $name): mixed
{
    $reflection = new ReflectionClass($object);

    do {
        if ($reflection->hasProperty($name)) {
            return $reflection->getProperty($name)->getValue($object);
        }
    } while ($reflection = $reflection->getParentClass());

    throw new LogicException("Property {$name} not found.");
}

function batch77TableOwner(): Component&HasTable
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

function batch77FindComponent(iterable $components, string $name): mixed
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

                $found = batch77FindComponent($children, $name);

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

it('covers create-count and CRM interaction unauthenticated actor guards', function (): void {
    auth()->logout();

    $create = new ReflectionClass(CreateInventoryCount::class);
    $page = $create->newInstanceWithoutConstructor();

    expect(fn (): mixed => new ReflectionMethod(CreateInventoryCount::class, 'actor')->invoke($page))
        ->toThrow(LogicException::class, 'authenticated actor');

    $manager = new CustomerInteractionsRelationManager;
    $manager->ownerRecord = CustomerProfile::factory()->create();

    $table = $manager->table(Table::make($manager));
    $action = collect($table->getHeaderActions())
        ->first(fn (mixed $candidate): bool => method_exists($candidate, 'getName') && $candidate->getName() === 'log_interaction');

    expect($action)->not->toBeNull();

    $run = $action->getActionFunction();

    expect(fn () => $run([]))
        ->toThrow(LogicException::class, 'authenticated CRM user');
});

it('covers non-array sales lines and journal repeater rows', function (): void {
    $probe = new class
    {
        use InteractsWithSalesServices;
    };

    $normalize = new ReflectionMethod($probe::class, 'normalizeLines');

    expect($normalize->invoke(null, [
        'skip-me',
        [
            'product_variant_id' => 7,
            'quantity' => 2,
        ],
    ]))->toHaveCount(1);

    $placeholder = JournalEntryLinesRepeater::totals();
    $content = batch77Property($placeholder, 'getConstantStateUsing');

    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->with('lines')->andReturn([
        'skip-me',
        ['debit' => '1.25', 'credit' => '1.25'],
    ]);

    expect($content($get))->toBeString();
});

it('covers confirmed supplier-confirmation and insufficient quotation colors', function (): void {
    $confirmations = new ConfirmationsRelationManager;
    $confirmationTable = $confirmations->table(Table::make($confirmations));
    $statusColumn = $confirmationTable->getColumn('confirmation_status');

    expect($statusColumn->getColor(SupplierConfirmationStatus::Confirmed))->toBe('success');

    $quotationTable = QuotationsTable::configure(Table::make(batch77TableOwner()));
    $coverageColumn = $quotationTable->getColumn('reservation_coverage');

    expect($coverageColumn->getColor('Insufficient'))->toBe('danger');
});

it('covers product-wide supplier capability and overdue supplier confirmation colors', function (): void {
    $supportTable = SupplierProductSupportResource::table(Table::make(batch77TableOwner()));
    $scope = $supportTable->getColumn('scope');
    $scopeState = batch77Property($scope, 'getStateUsing');

    $support = new SupplierProductSupport;
    $support->forceFill(['product_variant_id' => null]);

    expect($scopeState($support))->toBe('Product-wide');

    $confirmationTable = SupplierConfirmationResource::table(Table::make(batch77TableOwner()));
    $overdue = $confirmationTable->getColumn('overdue');

    expect($overdue->getColor('Overdue'))->toBe('danger');
});

it('covers the live sales-opportunity transcript branch', function (): void {
    $opportunity = new SalesOpportunity;
    $transcription = new VoiceNoteTranscription;
    $transcription->forceFill(['transcript' => 'Live transcript coverage']);

    $opportunity->setRelation('transcription', $transcription);

    $schema = SalesOpportunityInfolist::configure(Schema::make());
    $entry = batch77FindComponent(
        $schema->getComponents(withActions: false, withHidden: true),
        'origin_evidence',
    );

    expect($entry)->not->toBeNull();

    $state = batch77Property($entry, 'getConstantStateUsing');

    expect($state($opportunity))->toBe('Live transcript coverage');
});

it('covers missing recount and accept-variance reasons', function (): void {
    $count = InventoryCount::factory()->create();
    $manager = new InventoryCountLinesRelationManager;
    $manager->ownerRecord = $count;

    $table = $manager->table(Table::make($manager));
    $actions = collect($table->getRecordActions())->keyBy(
        fn (mixed $action): string => $action->getName(),
    );

    $line = new InventoryCountLine;

    $requestRecount = $actions->get('request_recount');
    expect($requestRecount)->not->toBeNull();
    expect(fn () => $requestRecount->getActionFunction()($line, ['reason' => null]))
        ->toThrow(LogicException::class, 'reason is required to request a recount');

    $acceptVariance = $actions->get('accept_variance');
    expect($acceptVariance)->not->toBeNull();
    expect(fn () => $acceptVariance->getActionFunction()($line, ['reason' => null]))
        ->toThrow(LogicException::class, 'reason is required to accept a flagged variance');
});
