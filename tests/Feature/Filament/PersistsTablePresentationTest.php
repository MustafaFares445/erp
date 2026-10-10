<?php

declare(strict_types=1);

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\User;
use App\Services\UiPreferences\UserUiPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Stores the invoice list layout a user saved earlier: every column as Filament records it, except `$column`,
 * whose toggle and the default it was saved against are given.
 */
function storeInvoiceLayout(User $user, string $column, bool $isToggled, bool $savedAgainstHiddenDefault): void
{
    $layout = collect(Livewire::actingAs($user)->test(ListInvoices::class)->instance()->getDefaultTableColumnState())
        ->map(static fn (array $item): array => $item['name'] === $column
            ? [...$item, 'isToggled' => $isToggled, 'isToggledHiddenByDefault' => $savedAgainstHiddenDefault]
            : $item)
        ->all();

    app(UserUiPreferenceService::class)->put($user, 'table', 'sales.invoices', ['columns' => $layout]);
}

it('applies a column default that changed after the user saved the layout', function (): void {
    $user = User::factory()->create();
    // `invoice_date` is hidden by default now; this layout was saved while it was still shown by default.
    storeInvoiceLayout($user, 'invoice_date', isToggled: true, savedAgainstHiddenDefault: false);

    $invoices = Livewire::actingAs($user)->test(ListInvoices::class)->instance();

    expect($invoices->isTableColumnToggledHidden('invoice_date'))->toBeTrue();
});

it('keeps a toggle the user chose against the current default', function (string $column, bool $isToggled, bool $savedAgainstHiddenDefault): void {
    $user = User::factory()->create();
    storeInvoiceLayout($user, $column, $isToggled, $savedAgainstHiddenDefault);

    $invoices = Livewire::actingAs($user)->test(ListInvoices::class)->instance();

    expect($invoices->isTableColumnToggledHidden($column))->toBe(! $isToggled);
})->with([
    'shown although hidden by default' => ['invoice_date', true, true],
    'hidden although shown by default' => ['due_date', false, false],
]);

it('stores the current default with the layout so the reset happens only once', function (): void {
    $user = User::factory()->create();
    storeInvoiceLayout($user, 'invoice_date', isToggled: true, savedAgainstHiddenDefault: false);

    Livewire::actingAs($user)->test(ListInvoices::class);

    $stored = collect(app(UserUiPreferenceService::class)->get($user, 'table', 'sales.invoices')['columns'] ?? [])
        ->firstWhere('name', 'invoice_date');

    expect($stored)->toMatchArray(['isToggled' => false, 'isToggledHiddenByDefault' => true]);
});
