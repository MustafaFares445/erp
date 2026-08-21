# Contract: `TransferResource` (Filament)

**Location**: `app/Filament/Resources/Transfers/` — mirrors `App\Filament\Resources\Adjustments`. Already referenced by `AdminModuleRegistry` (FQCN string; no registry edit).

**Hard constraint**: nothing under `App\Filament\Resources\Transfers` may `use` `InventoryStock` or `InventoryMovement` (ArchTest). Stock reads for display go through `Warehouse::currentAvailable()`; all stock writes go through `StockTransferService`.

## Resource

```php
final class TransferResource extends Resource
{
    protected static ?string $model = StockTransfer::class;
    protected static ?string $navigationGroup = 'admin.groups.inventory';
    // navigation icon per FI convention; navigationSort per registry index
}
```

- `getNavigationLabel()` → `__('admin.resources.transfers')` (key present).
- `form()` → `TransferForm::configure`; `infolist()` → `TransferInfolist::configure`; `table()` → `TransfersTable::configure`.
- `getRelations()` → `[TransferItemsRelationManager::class]`.
- `getPages()` → index / create / view / edit.
- `getRecordRouteBindingEloquentQuery()` strips `SoftDeletingScope` (so trashed drafts are reachable for view/restore).

## Form — `Schemas/TransferForm.php`

- Pulls `$rules = TransferData::rules()`.
- `Select::make('from_warehouse_id')->relationship('fromWarehouse','name', fn (Builder $q) => $q->where('is_active', true))->rules($rules['from_warehouse_id'])->searchable()->preload()->live()`.
- `Select::make('to_warehouse_id')->relationship('toWarehouse','name', fn (Builder $q) => $q->where('is_active', true))->rules($rules['to_warehouse_id'])` — **excludes the chosen source** (`->disableOptionWhen(fn ($value, Get $get) => $value === $get('from_warehouse_id'))`) and carries the `different:from_warehouse_id` rule.
- `TextInput::make('transfer_number')->disabled()->dehydrated(false)->placeholder(__('admin.inventory.transfer.number_pending'))`.
- `Textarea::make('notes')`.
- Whole schema `->disabled(fn (?StockTransfer $r) => $r?->isConfirmed() ?? false)`.

## Relation manager — `RelationManagers/TransferItemsRelationManager.php`

- `protected static string $relationship = 'items';`
- Form: `Select::make('product_variant_id')->relationship('productVariant','sku')->searchable()->required()->live()`; `TextInput::make('quantity')->numeric()->minValue(0)->rules(['gt:0'])->required()`; a read-only "available at source" display via `->state(fn () => $this->fromWarehouse()->currentAvailable((int) $variantId))` (never reads `InventoryStock`).
- Whole schema `->disabled(fn () => ! $this->transfer()->isDraft())`.
- Header/row/bulk item actions all `->visible(fn () => $this->transfer()->isDraft())`.
- Each create/edit/delete action `->after(fn () => $this->transfer()->touch())` so item edits register as an `inventory.transfer.edited` audit on the parent (see audit-log contract).
- Private helpers: `transfer(): StockTransfer` (narrows `getOwnerRecord()`); `fromWarehouse(): Warehouse`.

## Table — `Tables/TransfersTable.php`

- `defaultSort('created_at','desc')`.
- Columns: `transfer_number` (searchable/sortable, placeholder `number_pending`), `fromWarehouse.code`→`toWarehouse.code` (or a combined "A → B" column), `status` badge (`Str::headline`; `Draft`→warning, `Confirmed`→success), `items_count` (`->counts('items')`), `createdBy.name` (default "System"), `created_at`.
- Filters: `SelectFilter status`; `SelectFilter from_warehouse_id` (+ `to_warehouse_id`) relationship; custom `created_at` from/until `DatePicker` (`whereDate`); **`TrashedFilter`** (exposes discarded drafts).
- Row actions: `ViewAction`; `EditAction`/`DeleteAction` each `->visible(fn ($record) => $record->isDraft())`; `RestoreAction->visible(fn ($record) => $record->trashed())`; **no** `ForceDeleteAction`.

## Infolist — `Schemas/TransferInfolist.php`

- Header section (2 cols): number, status badge, from→to warehouse, notes, creator, timestamps.
- Items section: `RepeatableEntry::make('items')` — variant sku/name + quantity.
- Movements section `->visible(fn (StockTransfer $r) => $r->isConfirmed())`: `RepeatableEntry::make('movements')` showing type/warehouse/signed quantity, with the id entry `->url(fn ($record) => StockMovementResource::getUrl('view', ['record' => $record->getKey()]))` (importing the resource class is allowed; importing the ledger model is not).

## Pages

- `ListTransfers` — `getHeaderActions()` → `[CreateAction::make()]`.
- `CreateTransfer` — empty body (`created_by` via `TracksBlameable`).
- `EditTransfer` — header actions: View; `DeleteAction` (draft-only); `RestoreAction`; **no** `ForceDeleteAction`. Confirmed records 403 on edit via policy `update`.
- `ViewTransfer` (`use InteractsWithInventoryServices`) — hosts the **Confirm** action:

```php
Action::make('confirm')
    ->label(__('admin.inventory.transfer.confirm'))->color('success')
    ->authorize(fn (StockTransfer $record): bool => auth()->user()?->can('confirm', $record) ?? false)
    ->visible(fn (StockTransfer $record): bool => $record->isDraft() && (auth()->user()?->can('confirm', $record) ?? false))
    ->requiresConfirmation()
    ->action(function (StockTransfer $record): void {
        $actor = auth()->user();
        if (! $actor instanceof User) { return; }
        $this->runInventoryOperation(
            fn () => app(StockTransferService::class)->confirm($record, $actor),
            'admin.inventory.transfer.notifications.confirmed',
        );
    });
```

`runInventoryOperation()` (reused trait) converts a thrown `DomainException`/`ValidationException` into a danger notification and leaves state unchanged.

## Test obligations (`tests/Feature/Filament/TransferResourceTest.php`)

- Create a draft via `CreateTransfer` (fillForm + create) ⇒ no form errors, status `Draft`, `transfer_number` null.
- Same source/destination ⇒ form validation error (`different`).
- Zero/negative line quantity ⇒ relation-manager validation error.
- Add/edit/remove item lines on a draft persist; parent `updated_at` bumped (edit audit).
- Confirmed transfer: `EditAction`/`DeleteAction` hidden; direct edit URL 403.
- Trashed draft appears under `TrashedFilter`; `RestoreAction` restores it to `Draft`.
- Confirm action: hidden for a preparer without `confirm` permission (even on their own draft); visible for an approver; `->callAction('confirm')->assertNotified()` moves it to `Confirmed`.
- Unpermitted user hitting `TransferResource::getUrl('index')` ⇒ `assertForbidden()`.
- List filters by status / warehouse / date return the expected records.
