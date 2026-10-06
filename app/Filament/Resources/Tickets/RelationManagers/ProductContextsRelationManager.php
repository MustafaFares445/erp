<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Enums\QualityResolutionType;
use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\CustomerReturnRequest;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\TicketProductContext;
use App\Models\TicketQualityResolution;
use App\Models\User;
use App\Services\Support\TicketProductContextService;
use App\Services\Support\TicketQualityResolutionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The delivered product lines a quality complaint is about, and its
 * resolution. Shown only on product quality tickets while the feature is on.
 * Complaints never create Equipment 360 records or move stock.
 */
final class ProductContextsRelationManager extends RelationManager
{
    protected static string $relationship = 'productContexts';

    #[\Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Ticket
            && $ownerRecord->type === TicketType::ProductQualityIssue
            && TicketProductContextService::enabled();
    }

    #[\Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Product Quality');
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['productVariant', 'inventoryLot', 'unit', 'originalOperationLine.operation']))
            ->description(fn (): ?string => $this->resolutionLine())
            ->columns([
                TextColumn::make('productVariant.name')->label(__('Product')),
                TextColumn::make('inventoryLot.lot_number')->label(__('Lot'))->placeholder(__('—')),
                TextColumn::make('quantity')->label(__('Affected quantity'))->state(static fn (TicketProductContext $record): string => mb_rtrim(mb_rtrim((string) $record->quantity, '0'), '.').($record->unit !== null ? ' '.$record->unit->name : '')),
                TextColumn::make('originalOperationLine.operation.operation_number')->label(__('Delivery'))->placeholder(__('—')),
                TextColumn::make('notes')->label(__('Notes'))->placeholder(__('—'))->limit(60),
            ])
            ->headerActions([
                Action::make('addProductContext')
                    ->label(__('Add Product Line'))
                    ->schema([
                        Select::make('original_inventory_operation_line_id')
                            ->label(__('Delivered line'))
                            ->options(fn (): array => $this->lineOptions())
                            ->searchable()
                            ->required(),
                        TextInput::make('quantity')->label(__('Affected quantity'))->numeric()->minValue(0.000001)->required(),
                        TextInput::make('notes')->label(__('Notes')),
                    ])
                    ->authorize(fn (): bool => self::currentActor()->can('create', TicketProductContext::class))
                    ->visible(fn (): bool => ! $this->ticket()->qualityResolution()->exists())
                    ->action(fn (array $data) => $this->run(fn () => app(TicketProductContextService::class)->attach($this->ticket(), [$data], self::currentActor()))),
                Action::make('resolveComplaint')
                    ->label(__('Resolve Complaint'))
                    ->schema([
                        Select::make('resolution_type')->label(__('Resolution'))->options(QualityResolutionType::class)->required()->live(),
                        Textarea::make('notes')->label(__('Resolution notes'))->required(),
                        Select::make('customer_return_request_id')
                            ->label(__('Customer return request'))
                            ->helperText(__('Optional. Link an existing return request when the replacement, refund, or credit follows the established return workflow.'))
                            ->options(fn (): array => CustomerReturnRequest::query()
                                ->where('customer_id', $this->ticket()->customer_id)
                                ->latest('id')
                                ->limit(100)
                                ->get()
                                ->mapWithKeys(static fn (CustomerReturnRequest $request): array => [
                                    $request->id => $request->request_number.' · '.$request->status->label(),
                                ])
                                ->all())
                            ->searchable()
                            ->visible(static function (Get $get): bool {
                                $raw = $get('resolution_type');
                                $type = $raw instanceof QualityResolutionType
                                    ? $raw
                                    : QualityResolutionType::tryFrom(is_string($raw) ? $raw : '');

                                return $type?->mayLinkReturnRequest() ?? false;
                            }),
                        Select::make('supplier_id')
                            ->label(__('Responsible supplier'))
                            ->helperText(__("Leave empty to use the supplier from the lot's purchase history."))
                            ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->visible(static fn (Get $get): bool => $get('resolution_type') === QualityResolutionType::SupplierClaim->value),
                    ])
                    ->authorize(fn (): bool => self::currentActor()->can('create', TicketQualityResolution::class))
                    ->visible(fn (): bool => ! $this->ticket()->qualityResolution()->exists() && $this->ticket()->productContexts()->exists())
                    ->action(fn (array $data) => $this->run(function () use ($data): void {
                        $type = $data['resolution_type'] instanceof QualityResolutionType
                            ? $data['resolution_type']
                            : (QualityResolutionType::tryFrom(is_string($data['resolution_type'] ?? null) ? $data['resolution_type'] : '') ?? QualityResolutionType::NoDefectFound);

                        app(TicketQualityResolutionService::class)->resolve($this->ticket(), $type, self::currentActor(), [
                            'notes' => is_string($data['notes'] ?? null) ? $data['notes'] : null,
                            'customer_return_request_id' => is_numeric($data['customer_return_request_id'] ?? null) ? (int) $data['customer_return_request_id'] : null,
                            'supplier_id' => is_numeric($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null,
                        ]);
                    })),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    private function resolutionLine(): ?string
    {
        $resolution = $this->ticket()->qualityResolution()->with('customerReturnRequest')->first();

        if (! $resolution instanceof TicketQualityResolution) {
            return null;
        }

        return collect([
            self::t('Resolution').': '.$resolution->resolution_type->label(),
            $resolution->resolved_at->toDayDateTimeString(),
            $resolution->customerReturnRequest !== null ? self::t('Return request :number', ['number' => $resolution->customerReturnRequest->request_number]) : null,
            $resolution->notes,
        ])->filter()->implode(' · ');
    }

    /** @return array<int, string> */
    private function lineOptions(): array
    {
        $customer = CustomerProfile::query()->find($this->ticket()->customer_id);

        return $customer instanceof CustomerProfile ? app(TicketProductContextService::class)->lineOptions($customer) : [];
    }

    /** @param array<string, scalar> $replace */
    private static function t(string $key, array $replace = []): string
    {
        return (string) __($key, $replace);
    }

    /** Runs a service call, turning a domain failure into a notification. */
    private function run(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $validationException) {
            Notification::make()->danger()->title(__('Unable to update the complaint'))
                ->body(collect($validationException->errors())->flatten()->implode(' '))
                ->send();
        }
    }

    private function ticket(): Ticket
    {
        $ticket = $this->getOwnerRecord();

        return $ticket instanceof Ticket ? $ticket : throw new LogicException('Expected the owner record to be a Ticket.');
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        return $actor instanceof User ? $actor : throw new LogicException('An authenticated User is required.');
    }
}
