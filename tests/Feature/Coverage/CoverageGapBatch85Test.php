<?php

declare(strict_types=1);

use App\Enums\QualityResolutionType;
use App\Enums\TicketType;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\ProductContextsRelationManager;
use App\Models\CustomerProfile;
use App\Models\CustomerReturnRequest;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\Ticket;
use App\Models\TicketQualityResolution;
use App\Models\User;
use App\Services\Support\TicketProductContextService;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    config(['support.product_quality_enabled' => true]);
});

function coverage85Panel(User $user, Ticket $ticket)
{
    return Livewire::actingAs($user)->test(ProductContextsRelationManager::class, [
        'ownerRecord' => $ticket,
        'pageClass' => ViewTicket::class,
    ]);
}

it('covers product-quality relation visibility title owner and actor guards', function (): void {
    $ticket = Ticket::factory()->create(['type' => TicketType::ProductQualityIssue]);

    expect(ProductContextsRelationManager::canViewForRecord($ticket, ViewTicket::class))->toBeTrue()
        ->and(ProductContextsRelationManager::getTitle($ticket, ViewTicket::class))->toBe('Product Quality');

    $ticket->update(['type' => TicketType::GeneralSupport]);
    expect(ProductContextsRelationManager::canViewForRecord($ticket->refresh(), ViewTicket::class))->toBeFalse();

    $ticket->update(['type' => TicketType::ProductQualityIssue]);
    config(['support.product_quality_enabled' => false]);
    expect(ProductContextsRelationManager::canViewForRecord($ticket->refresh(), ViewTicket::class))->toBeFalse();

    $manager = new ProductContextsRelationManager;
    $manager->ownerRecord = CustomerProfile::factory()->create();

    expect(fn (): mixed => new ReflectionMethod(ProductContextsRelationManager::class, 'ticket')->invoke($manager))
        ->toThrow(LogicException::class, 'Expected the owner record to be a Ticket');

    auth()->logout();
    expect(fn (): mixed => new ReflectionMethod(ProductContextsRelationManager::class, 'currentActor')->invoke(null))
        ->toThrow(LogicException::class, 'authenticated User');
});

it('builds product-quality table actions and converts validation failures to notifications', function (): void {
    $user = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create(['type' => TicketType::ProductQualityIssue]);

    $operation = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $ticket->customer_id,
    ]);
    $line = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->id,
        'quantity' => '2.000000',
        'transaction_quantity' => '2.000000',
        'serialized_inventory_unit_id' => null,
    ]);

    coverage85Panel($user, $ticket)
        ->assertActionVisible(TestAction::make('addProductContext')->table())
        ->assertActionHidden(TestAction::make('resolveComplaint')->table())
        ->callAction(TestAction::make('addProductContext')->table(), [
            'original_inventory_operation_line_id' => $line->id,
            'quantity' => '1',
            'notes' => 'Coverage delivered line',
        ])
        ->assertHasNoFormErrors();

    expect($ticket->productContexts()->count())->toBe(1);

    coverage85Panel($user, $ticket)
        ->assertActionVisible(TestAction::make('resolveComplaint')->table())
        ->callAction(TestAction::make('resolveComplaint')->table(), [
            'resolution_type' => QualityResolutionType::NoDefectFound->value,
            'notes' => 'Inspection found no reproducible product defect.',
        ])
        ->assertHasNoFormErrors();

    expect($ticket->qualityResolution()->exists())->toBeTrue();
});

it('covers resolution summary return-request link line options and helper translation', function (): void {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    $ticket = Ticket::factory()->create(['type' => TicketType::ProductQualityIssue]);
    $return = CustomerReturnRequest::factory()->create([
        'customer_id' => $ticket->customer_id,
    ]);

    TicketQualityResolution::factory()->create([
        'ticket_id' => $ticket->id,
        'resolution_type' => QualityResolutionType::Replacement,
        'customer_return_request_id' => $return->id,
        'notes' => 'Replacement follows the approved return request.',
        'resolved_at' => now(),
    ]);

    $manager = new ProductContextsRelationManager;
    $manager->ownerRecord = $ticket;

    $line = new ReflectionMethod(ProductContextsRelationManager::class, 'resolutionLine')->invoke($manager);
    expect($line)->toBeString()
        ->toContain(QualityResolutionType::Replacement->label())
        ->toContain($return->request_number)
        ->toContain('Replacement follows the approved return request.');

    expect(new ReflectionMethod(ProductContextsRelationManager::class, 'lineOptions')->invoke($manager))->toBeArray()
        ->and(new ReflectionMethod(ProductContextsRelationManager::class, 't')->invoke(null, 'Return request :number', ['number' => 'RR-X']))
        ->toContain('RR-X');

    $emptyTicket = Ticket::factory()->create(['type' => TicketType::ProductQualityIssue]);
    $manager->ownerRecord = $emptyTicket;
    expect(new ReflectionMethod(ProductContextsRelationManager::class, 'resolutionLine')->invoke($manager))->toBeNull();

    $callback = static function (): never {
        throw ValidationException::withMessages(['field' => ['One error.', 'Another error.']]);
    };
    expect(new ReflectionMethod(ProductContextsRelationManager::class, 'run')->invoke($manager, $callback))->toBeNull();
});

it('covers return-request options and invalid resolution fallback in the complaint action', function (): void {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    $ticket = Ticket::factory()->create(['type' => TicketType::ProductQualityIssue]);
    $return = CustomerReturnRequest::factory()->create([
        'customer_id' => $ticket->customer_id,
    ]);

    $operation = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $ticket->customer_id,
    ]);
    $line = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'quantity' => '1.000000',
        'transaction_quantity' => '1.000000',
        'serialized_inventory_unit_id' => null,
    ]);
    app(TicketProductContextService::class)->attach(
        $ticket,
        [[
            'original_inventory_operation_line_id' => $line->getKey(),
            'quantity' => '1.000000',
            'notes' => null,
        ]],
        $user,
    );

    $manager = new ProductContextsRelationManager;
    $manager->ownerRecord = $ticket;
    $manager->pageClass = ViewTicket::class;
    $table = $manager->table(Table::make($manager));
    $action = collect($table->getHeaderActions())
        ->first(static fn (mixed $candidate): bool => method_exists($candidate, 'getName')
            && $candidate->getName() === 'resolveComplaint');

    expect($action)->not->toBeNull();

    $schema = $action->getSchema(Schema::make($manager));
    $returnSelect = collect($schema?->getFlatComponents(withHidden: true) ?? [])
        ->first(static fn (mixed $component): bool => method_exists($component, 'getName')
            && $component->getName() === 'customer_return_request_id');

    expect($returnSelect)->not->toBeNull()
        ->and($returnSelect->getOptions())->toHaveKey($return->getKey());

    $run = $action->getActionFunction();
    $run([
        'resolution_type' => 'not-a-resolution',
        'notes' => 'Fallback coverage resolution.',
        'customer_return_request_id' => null,
        'supplier_id' => null,
    ]);

    expect($ticket->qualityResolution()->firstOrFail()->resolution_type)
        ->toBe(QualityResolutionType::NoDefectFound);
});
