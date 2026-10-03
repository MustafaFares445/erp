<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Resources\DocumentTemplates\Pages\EditDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Pages\ListDocumentTemplates;
use App\Models\NotificationTemplate;
use App\Models\User;
use Database\Seeders\NotificationTemplateSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function documentTemplateRecord(NotificationEventKey $event): NotificationTemplate
{
    return NotificationTemplate::query()
        ->whereKey(NotificationTemplate::query()->where('key', $event->value)->min('id'))
        ->sole();
}

beforeEach(function (): void {
    (new NotificationTemplateSeeder)->run();
    $this->admin = User::factory()->admin()->create();
});

/**
 * WP-3.7 (GAP-UI-07, MD-08) — DocumentTemplateResource is the same business
 * editor as the notification messages, scoped to the invoice document events.
 */
it('lists one business row per invoice document, scoped away from other notifications', function (): void {
    $component = Livewire::actingAs($this->admin)
        ->test(ListDocumentTemplates::class)
        ->assertSuccessful()
        ->assertCountTableRecords(4)
        ->assertCanSeeTableRecords([
            documentTemplateRecord(NotificationEventKey::InvoiceIssued),
            documentTemplateRecord(NotificationEventKey::InvoiceOverdue60),
        ])
        ->assertCanNotSeeTableRecords([documentTemplateRecord(NotificationEventKey::VisitDue)])
        ->assertSee('Invoice issued')
        ->assertSee('English · العربية')
        ->assertDontSee('invoice.issued');

    $table = $component->instance()->getTable();

    expect($table->getColumn('channel'))->toBeNull()
        ->and($table->getColumn('locale'))->toBeNull()
        ->and($table->getFilters())->toBe([]);
});

it('offers no creation, deletion or technical controls', function (): void {
    $record = documentTemplateRecord(NotificationEventKey::InvoiceIssued);

    Livewire::actingAs($this->admin)
        ->test(ListDocumentTemplates::class)
        ->assertTableActionExists('edit')
        ->assertTableActionExists('reset_to_default')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableActionDoesNotExist('preview');

    expect(DocumentTemplateResource::canCreate())->toBeFalse()
        ->and(DocumentTemplateResource::canDelete($record))->toBeFalse()
        ->and(DocumentTemplateResource::canDeleteAny())->toBeFalse()
        ->and(array_keys(DocumentTemplateResource::getPages()))->toBe(['index', 'edit'])
        ->and(DocumentTemplateResource::getRecordTitle($record))->toBe('Invoice issued');
});

it('edits an invoice document with the business editor and no raw placeholders', function (): void {
    $record = documentTemplateRecord(NotificationEventKey::InvoiceOverdue7);

    $component = Livewire::actingAs($this->admin)
        ->test(EditDocumentTemplate::class, ['record' => $record->getKey()])
        ->assertSuccessful()
        ->assertSee('Invoice overdue by 7 days')
        ->assertSee('Insert information')
        ->assertSee('Days overdue')
        ->assertDontSee('{{')
        ->assertDontSee('Channel')
        ->assertFormFieldExists('content')
        ->assertFormFieldDoesNotExist('variables');

    expect(array_values($component->instance()->getBreadcrumbs()))->toBe(['Document Templates', 'Invoice overdue by 7 days']);

    $component
        ->fillForm(['content.en_mail.body' => 'Please pay {{ amount_due }} for {{ invoice_number }}.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(NotificationTemplate::query()
        ->where('key', NotificationEventKey::InvoiceOverdue7->value)
        ->where('locale', 'en')
        ->where('channel', NotificationChannel::Mail->value)
        ->value('body'))->toBe('Please pay {{ amount_due }} for {{ invoice_number }}.');
});

it('rejects document wording that uses unavailable information', function (): void {
    $record = documentTemplateRecord(NotificationEventKey::InvoiceIssued);

    Livewire::actingAs($this->admin)
        ->test(EditDocumentTemplate::class, ['record' => $record->getKey()])
        ->fillForm(['content.en_mail.body' => 'Hello {{ not_a_real_field }}'])
        ->call('save')
        ->assertHasFormErrors(['content']);
});

it('resets a document to its seeded default content', function (): void {
    $template = NotificationTemplate::query()
        ->where('key', NotificationEventKey::InvoiceIssued->value)
        ->where('locale', 'en')
        ->where('channel', NotificationChannel::Mail->value)
        ->sole();
    $original = $template->body;

    $template->forceFill(['body' => 'A broken edit that should not survive.'])->save();

    Livewire::actingAs($this->admin)
        ->test(ListDocumentTemplates::class)
        ->callAction(TestAction::make('reset_to_default')->table(documentTemplateRecord(NotificationEventKey::InvoiceIssued)))
        ->assertHasNoActionErrors();

    expect($template->refresh()->body)->toBe($original);
});

it('resolves the document templates registry entry to a real resource', function (): void {
    expect(class_exists(DocumentTemplateResource::class))->toBeTrue();
});
