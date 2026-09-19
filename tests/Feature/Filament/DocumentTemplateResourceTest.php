<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Resources\DocumentTemplates\Pages\ListDocumentTemplates;
use App\Models\NotificationTemplate;
use App\Models\User;
use Database\Seeders\NotificationTemplateSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * WP-3.7 (GAP-UI-07, MD-08) — DocumentTemplateResource is a thin, real
 * screen over the invoice-document NotificationTemplate rows, not a
 * placeholder any more.
 */
it('lists only the invoice document templates, scoped away from other notification templates', function (): void {
    (new NotificationTemplateSeeder)->run();
    $actor = User::factory()->admin()->create();

    $visitTemplate = NotificationTemplate::query()
        ->where('key', NotificationEventKey::VisitDue->value)
        ->first();

    expect($visitTemplate)->not->toBeNull();

    $invoiceTemplate = NotificationTemplate::query()
        ->where('key', NotificationEventKey::InvoiceIssued->value)
        ->where('locale', 'en')
        ->where('channel', NotificationChannel::Mail->value)
        ->sole();

    Livewire::actingAs($actor)
        ->test(ListDocumentTemplates::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$invoiceTemplate])
        ->assertCanNotSeeTableRecords(NotificationTemplate::query()->where('key', NotificationEventKey::VisitDue->value)->get());
});

it('restores a document template to its seeded default content', function (): void {
    (new NotificationTemplateSeeder)->run();
    $actor = User::factory()->admin()->create();

    $template = NotificationTemplate::query()
        ->where('key', NotificationEventKey::InvoiceIssued->value)
        ->where('locale', 'en')
        ->where('channel', NotificationChannel::Mail->value)
        ->sole();
    $original = $template->body;

    $template->forceFill(['body' => 'A broken edit that should not survive.'])->save();

    Livewire::actingAs($actor)
        ->test(ListDocumentTemplates::class)
        ->callAction(TestAction::make('restore_default')->table($template))
        ->assertHasNoActionErrors();

    expect($template->refresh()->body)->toBe($original);
});

it('resolves the document templates registry entry to a real resource', function (): void {
    expect(class_exists(DocumentTemplateResource::class))->toBeTrue();
});

it('previews a document template with sample values for declared variables', function (): void {
    (new NotificationTemplateSeeder)->run();
    $actor = User::factory()->admin()->create();

    $template = NotificationTemplate::query()
        ->where('key', NotificationEventKey::InvoiceIssued->value)
        ->where('locale', 'en')
        ->where('channel', NotificationChannel::Mail->value)
        ->sole();

    $template->forceFill([
        'variables' => ['invoice_number', '', 123, 'total_amount'],
    ])->save();

    Livewire::actingAs($actor)
        ->test(ListDocumentTemplates::class)
        ->callAction(TestAction::make('preview')->table($template))
        ->assertNotified()
        ->assertHasNoActionErrors();
});

it('notifies when a document template has no seeded default to restore', function (): void {
    $actor = User::factory()->admin()->create();
    $template = NotificationTemplate::query()->create([
        'key' => NotificationEventKey::InvoiceIssued->value,
        'locale' => 'zz',
        'channel' => NotificationChannel::Mail,
        'is_active' => true,
        'subject' => 'Custom subject',
        'body' => 'Custom body',
        'variables' => [],
    ]);

    $component = Livewire::actingAs($actor)->test(ListDocumentTemplates::class);
    $action = $component->instance()->getTable()->getAction('restore_default');

    expect($action)->not->toBeNull();

    $action->record($template)->call();

    $component->assertNotified();
    expect($template->refresh()->body)->toBe('Custom body');
});
