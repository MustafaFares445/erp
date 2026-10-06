<?php

declare(strict_types=1);

use App\Enums\CampaignChannel;
use App\Enums\CustomFieldEntityType;
use App\Enums\NotificationChannel;
use App\Filament\Resources\Campaigns\Schemas\CampaignForm;
use App\Filament\Resources\CustomerReturnRequests\Pages\ListCustomerReturnRequests;
use App\Filament\Resources\CustomerReturnRequests\Schemas\CustomerReturnRequestInfolist;
use App\Filament\Resources\CustomerReturnRequests\Tables\CustomerReturnRequestsTable;
use App\Filament\Resources\CustomFieldDefinitions\CustomFieldDefinitionResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryReports\Schemas\InventoryExportRequestSchema;
use App\Filament\Resources\InventoryReports\Tables\InventoryReportFilters;
use App\Filament\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Filament\Resources\Leads\Actions\LeadActions;
use App\Filament\Resources\Payments\Schemas\PaymentInfolist;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\Returns\ReturnResource;
use App\Filament\Resources\SerializedInventoryUnits\Schemas\SerializedInventoryUnitInfolist;
use App\Filament\Resources\Tickets\Schemas\TicketForm;
use App\Models\CustomerProfile;
use App\Models\CustomerReturnRequest;
use App\Models\CustomFieldDefinition;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\InvoiceDeliveryLink;
use App\Models\NotificationTemplate;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\WarrantyPolicy;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\HasLabel;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Unique;
use Livewire\Component;
use Livewire\Livewire;

uses(RefreshDatabase::class);

enum Coverage94OptionLabel: string implements HasLabel
{
    case Html = 'html';
    case Fallback = 'fallback';

    public function getLabel(): string|Htmlable|null
    {
        return $this === self::Html ? new HtmlString('<b>HTML</b>') : null;
    }
}

it('preserves HTML labels and falls back to enum values for unlabelled options', function (string $mapper): void {
    expect(new ReflectionMethod($mapper, 'enumOptions')->invoke(null, Coverage94OptionLabel::cases()))
        ->toBe(['html' => '<b>HTML</b>', 'fallback' => 'fallback']);
})->with([InventoryExportRequestSchema::class, InventoryReportFilters::class, LeadActions::class]);

final class Coverage94SchemaHost extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public mixed $warranty_policy_id = null;

    public array $data = [];

    public function render(): string
    {
        return '<div></div>';
    }
}

function coverage94Schema(): Schema
{
    return Schema::make(Livewire::test(Coverage94SchemaHost::class)->instance());
}

it('returns no delivered-line choices when the ticket customer is missing or has no deliveries', function (): void {
    $customer = CustomerProfile::factory()->create();
    $host = Livewire::test(Coverage94SchemaHost::class)->instance();
    $host->data = ['customer_id' => (string) $customer->id, 'product_contexts' => ['row' => []]];

    $repeater = new ReflectionMethod(TicketForm::class, 'productContexts')->invoke(null);
    $field = $repeater->getDefaultChildComponents()[0];
    Schema::make($host)->statePath('data.product_contexts.row')->components([$field])->getComponents();

    expect($field->getOptions())->toBe([]);
    $host->data['customer_id'] = null;
    expect($field->getOptions())->toBe([]);
});

it('scopes the custom-field unique code rule to the selected entity type', function (): void {
    $host = Livewire::test(Coverage94SchemaHost::class)->instance();
    $host->data = ['entity_type' => CustomFieldEntityType::Customer->value, 'code' => 'zone'];

    $schema = CustomFieldDefinitionResource::form(
        Schema::make($host)->model(CustomFieldDefinition::class)->statePath('data'),
    );
    $field = collect($schema->getFlatComponents(withHidden: true))->first(
        static fn (mixed $component): bool => $component instanceof TextInput && $component->getName() === 'code',
    );
    $rule = collect($field->getValidationRules())->first(static fn (mixed $rule): bool => $rule instanceof Unique);
    expect((string) $rule)->toContain('entity_type,"customer"');
    $host->data['entity_type'] = null;
    $rule = collect($field->getValidationRules())->first(static fn (mixed $rule): bool => $rule instanceof Unique);
    expect((string) $rule)->toContain('entity_type,""');
});

it('describes an unallocated posted payment as a customer deposit', function (): void {
    $payment = new Payment;
    $payment->forceFill(['posted_at' => now()]);
    $payment->setRelation('allocations', new Collection);

    $section = new ReflectionMethod(PaymentInfolist::class, 'allocations')->invoke(null);
    $schema = Schema::make(Livewire::test(Coverage94SchemaHost::class)->instance())->record($payment)->components([$section]);
    $schema->getComponents();

    expect($section->getDescription())->toBe(__('admin.sales.payment_ui.no_allocations_deposit'));
});

it('links an invoice delivery entry to its inventory operation', function (): void {
    $operation = InventoryOperation::factory()->delivery()->done()->create();
    $link = new InvoiceDeliveryLink;
    $link->setRelation('inventoryOperation', $operation);

    $section = new ReflectionMethod(InvoiceInfolist::class, 'deliveriesSection')->invoke(null);
    $entry = $section->getDefaultChildComponents()[0]->getDefaultChildComponents()[0];
    Schema::make(Livewire::test(Coverage94SchemaHost::class)->instance())->record($link)->components([$entry])->getComponents();
    expect($entry->getUrl())->toBe(InventoryOperationResource::getUrl('view', ['record' => $operation]));
});

it('links a customer return request to the resulting return from both detail and list entries', function (): void {
    $return = InventoryReturn::factory()->customer()->create();
    $request = CustomerReturnRequest::factory()->create(['resulting_inventory_return_id' => $return->id]);
    $url = ReturnResource::getUrl('view', ['record' => $return->id]);
    $schema = CustomerReturnRequestInfolist::configure(
        Schema::make(Livewire::test(Coverage94SchemaHost::class)->instance())->record($request),
    );
    $entry = collect($schema->getFlatComponents(withHidden: true))->first(static fn (mixed $component): bool => $component instanceof TextEntry && $component->getName() === 'resultingInventoryReturn.return_number');
    expect($entry->getUrl())->toBe($url);

    $page = new ListCustomerReturnRequests;
    $table = CustomerReturnRequestsTable::configure(Table::make($page));
    expect($table->getColumn('resultingInventoryReturn.return_number')->record($request)->getUrl())->toBe($url);
});

it('labels an active preventive schedule on a serialized unit', function (): void {
    $schema = SerializedInventoryUnitInfolist::configure(
        Schema::make(Livewire::test(Coverage94SchemaHost::class)->instance())->record(SerializedInventoryUnit::factory()->create()),
    );
    $repeater = collect($schema->getFlatComponents(withHidden: true))->first(static fn (mixed $component): bool => $component instanceof RepeatableEntry && $component->getName() === 'preventiveSchedules');
    $entry = collect($repeater->getDefaultChildComponents())->first(static fn (mixed $component): bool => $component instanceof TextEntry && $component->getName() === 'is_active');
    Schema::make(Livewire::test(Coverage94SchemaHost::class)->instance())->components([$entry])->getComponents();
    expect($entry->formatState(true))->toBe(__('admin.inventory.serialized_unit.values.active'));
});

it('covers campaign template option filtering for every supported channel and unsupported values', function (): void {
    $mail = NotificationTemplate::query()->create([
        'key' => 'coverage94.mail',
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => 'Mail',
        'body' => 'Mail',
        'variables' => [],
        'is_active' => true,
    ]);
    $sms = NotificationTemplate::query()->create([
        'key' => 'coverage94.sms',
        'locale' => 'en',
        'channel' => NotificationChannel::Sms,
        'subject' => null,
        'body' => 'SMS',
        'variables' => [],
        'is_active' => true,
    ]);
    $whatsapp = NotificationTemplate::query()->create([
        'key' => 'coverage94.whatsapp',
        'locale' => 'en',
        'channel' => NotificationChannel::Whatsapp,
        'subject' => null,
        'body' => 'WA',
        'variables' => [],
        'is_active' => true,
    ]);
    NotificationTemplate::query()->create([
        'key' => 'coverage94.inactive',
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => 'Inactive',
        'body' => 'Inactive',
        'variables' => [],
        'is_active' => false,
    ]);

    $schema = CampaignForm::configure(coverage94Schema());
    $components = collect($schema->getFlatComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Select)
        ->keyBy(fn (Select $component): string => $component->getName());

    /** @var Select $templateSelect */
    $templateSelect = $components['content_template_id'];
    $property = new ReflectionProperty($templateSelect, 'options');
    $options = $property->getValue($templateSelect);

    expect($options)->toBeInstanceOf(Closure::class);

    $for = static fn (mixed $channel): array => $options(
        static fn (string $path): mixed => $path === 'channel' ? $channel : null,
    );

    expect($for(CampaignChannel::Email->value))->toHaveKey($mail->id)
        ->and($for(CampaignChannel::Email->value))->not->toHaveKey(NotificationTemplate::query()->where('key', 'coverage94.inactive')->value('id'))
        ->and($for(CampaignChannel::Sms->value))->toHaveKey($sms->id)
        ->and($for(CampaignChannel::Whatsapp->value))->toHaveKey($whatsapp->id)
        ->and($for(CampaignChannel::Event->value))->toBe([])
        ->and($for('invalid'))->toBe([])
        ->and($for(null))->toBe([])
        ->and($for(12))->toBe([]);
});

it('covers product-variant warranty policy summary missing unavailable covered and empty-coverage branches', function (): void {
    $contentFor = static function (mixed $policyId): string {
        $host = Livewire::test(Coverage94SchemaHost::class)->instance();
        $host->warranty_policy_id = $policyId;

        $schema = ProductVariantResource::form(Schema::make($host)->model(ProductVariant::class));
        $components = collect($schema->getFlatComponents(withHidden: true))
            ->filter(fn (mixed $component): bool => $component instanceof Placeholder)
            ->keyBy(fn (Placeholder $component): string => $component->getName());

        /** @var Placeholder $summary */
        $summary = $components['warranty_policy_summary'];

        return (string) $summary->getContent();
    };

    expect($contentFor(null))->toContain('No policy selected')
        ->and($contentFor(999999999))->toBe('Warranty policy unavailable.');

    $covered = WarrantyPolicy::factory()->create([
        'duration_value' => 12,
        'covers_parts' => true,
        'covers_labour' => true,
        'covers_travel' => false,
        'covers_consumables' => false,
        'covers_third_party' => true,
    ]);

    expect($contentFor($covered->id))
        ->toContain('12', 'Parts', 'Labour', 'Third-party services');

    $none = WarrantyPolicy::factory()->create([
        'duration_value' => 6,
        'covers_parts' => false,
        'covers_labour' => false,
        'covers_travel' => false,
        'covers_consumables' => false,
        'covers_third_party' => false,
    ]);

    expect($contentFor((string) $none->id))
        ->toContain('6', 'no charge categories');
});
