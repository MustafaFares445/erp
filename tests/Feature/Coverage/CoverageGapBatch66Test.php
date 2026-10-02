<?php

declare(strict_types=1);

use App\Data\Crm\CampaignData;
use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Enums\InventoryReportType;
use App\Enums\NotificationChannel;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Models\Campaign;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\NotificationTemplate;
use App\Models\PurchaseOrder;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Crm\CampaignService;
use App\Services\Inventory\InventoryReportService;
use App\Services\Purchasing\SupplierConfirmationService;
use App\Services\Sales\QuotationService;
use App\Services\Support\TicketTriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);

    Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );
});

function batch66Method(string $class, string $method): ReflectionMethod
{
    return new ReflectionMethod($class, $method);
}

it('covers service quotation validation for invalid quantity description and negative price', function (): void {
    $customer = CustomerProfile::factory()->create();
    $service = app(QuotationService::class);

    expect(fn () => $service->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => today()->toDateString()],
        [[
            'product_variant_id' => null,
            'quantity' => 0,
            'description' => '',
            'unit_price' => 10,
        ]],
    ))->toThrow(ValidationException::class, 'Service quotation lines require a description and positive quantity.');

    expect(fn () => $service->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => today()->toDateString()],
        [[
            'product_variant_id' => null,
            'quantity' => 1,
            'description' => 'Diagnostic service',
            'unit_price' => -1,
        ]],
    ))->toThrow(ValidationException::class, 'Service quotation lines require a non-negative unit price.');
});

it('covers every campaign delivery-channel match arm for persisted and incoming templates', function (): void {
    $service = app(CampaignService::class);

    $mail = NotificationTemplate::query()->create([
        'key' => 'batch66.mail',
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => 'Mail',
        'body' => 'Mail',
        'variables' => [],
        'is_active' => true,
    ]);
    $sms = NotificationTemplate::query()->create([
        'key' => 'batch66.sms',
        'locale' => 'en',
        'channel' => NotificationChannel::Sms,
        'subject' => null,
        'body' => 'SMS',
        'variables' => [],
        'is_active' => true,
    ]);
    $whatsapp = NotificationTemplate::query()->create([
        'key' => 'batch66.whatsapp',
        'locale' => 'en',
        'channel' => NotificationChannel::Whatsapp,
        'subject' => null,
        'body' => 'WA',
        'variables' => [],
        'is_active' => true,
    ]);

    $persisted = batch66Method(CampaignService::class, 'assertCampaignTemplateDeliverable');
    $incoming = batch66Method(CampaignService::class, 'assertTemplateMatchesChannel');

    $actor = User::factory()->create();

    foreach ([
        [CampaignChannel::Sms, $sms],
        [CampaignChannel::Whatsapp, $whatsapp],
    ] as $index => [$channel, $template]) {
        $campaign = Campaign::query()->forceCreate([
            'campaign_number' => 'CMP-B66-'.($index + 1),
            'name' => 'Batch 66 persisted '.$channel->value,
            'channel' => $channel,
            'status' => CampaignStatus::Draft,
            'content_template_id' => $template->getKey(),
            'created_by' => $actor->getKey(),
        ]);
        $persisted->invoke($service, $campaign->fresh('contentTemplate'));

        $incoming->invoke($service, new CampaignData(
            name: 'Batch 66 '.$channel->value,
            channel: $channel,
            contentTemplateId: (int) $template->getKey(),
        ));
    }

    $eventCampaign = Campaign::query()->forceCreate([
        'campaign_number' => 'CMP-B66-EVENT',
        'name' => 'Batch 66 event mismatch',
        'channel' => CampaignChannel::Event,
        'status' => CampaignStatus::Draft,
        'content_template_id' => $mail->getKey(),
        'created_by' => $actor->getKey(),
    ]);
    expect(fn (): mixed => $persisted->invoke($service, $eventCampaign->fresh('contentTemplate')))
        ->toThrow(DomainException::class, 'no longer matches');

    expect(fn (): mixed => $incoming->invoke($service, new CampaignData(
        name: 'Event mismatch',
        channel: CampaignChannel::Other,
        contentTemplateId: (int) $mail->getKey(),
    )))->toThrow(DomainException::class, 'does not match');
});

it('covers supplier confirmation unsent-order guard and successful quantity normalization', function (): void {
    $actor = User::factory()->admin()->create();
    $order = PurchaseOrder::factory()->accepted()->create([
        'supplier_confirmation_required' => true,
        'sent_at' => null,
    ]);

    expect(fn () => app(SupplierConfirmationService::class)->recordPurchaseOrder($actor, $order))
        ->toThrow(ValidationException::class, 'Send the Purchase Order');

    $normalize = batch66Method(SupplierConfirmationService::class, 'normalizeQuantity');
    expect($normalize->invoke(app(SupplierConfirmationService::class), '1.25', 'quantity'))
        ->toBe('1.250000');
});

it('covers ticket triage missing-customer guard and enum-instance helpers', function (): void {
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $ticket = Ticket::factory()->for($customer, 'customer')->create([
        'status' => TicketStatus::Pending,
    ]);
    $customer->delete();

    expect(fn () => app(TicketTriageService::class)->triage($ticket, [
        'equipment_source' => TicketEquipmentSource::External->value,
        'external_equipment_name' => 'Detached customer equipment',
        'service_path' => TicketServicePath::RemoteSupport->value,
        'billing_decision' => 'no_charge',
    ], $actor))->toThrow(DomainException::class, 'requires a customer profile');

    $equipmentSource = batch66Method(TicketTriageService::class, 'equipmentSource');
    $servicePath = batch66Method(TicketTriageService::class, 'servicePath');

    expect($equipmentSource->invoke(app(TicketTriageService::class), TicketEquipmentSource::External))
        ->toBe(TicketEquipmentSource::External)
        ->and($servicePath->invoke(app(TicketTriageService::class), TicketServicePath::RemoteSupport))
        ->toBe(TicketServicePath::RemoteSupport);
});

it('covers expiry report warehouse filtering', function (): void {
    $query = app(InventoryReportService::class)->query(InventoryReportType::ExpiryLots, [
        'warehouse_id' => 123,
    ]);

    expect($query->toSql())->toContain('exists');
});
