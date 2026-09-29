<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryConditionChanges\Pages;

use App\Enums\InventoryConditionChangeType;
use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Models\InventoryConditionChange;
use App\Models\User;
use App\Services\Inventory\DisposalEvidenceSynchronizer;
use App\Services\Inventory\InventoryConditionChangeService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

final class ViewInventoryConditionChange extends ViewRecord
{
    use InteractsWithInventoryServices;

    protected static string $resource = InventoryConditionChangeResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('attach_evidence')
                ->label(__('admin.inventory.condition_change.actions.attach_evidence'))
                ->color('gray')
                ->visible(fn (InventoryConditionChange $record): bool => $record->isDraft()
                    && $record->type === InventoryConditionChangeType::Disposal
                    && (auth()->user()?->can('create', InventoryConditionChange::class) ?? false))
                ->schema([
                    FileUpload::make('evidence')
                        ->label(__('admin.inventory.condition_change.evidence_files'))
                        ->disk('local')
                        ->directory('disposal-evidence')
                        ->visibility('private')
                        ->multiple()
                        ->maxSize(10240)
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                        ->required(),
                ])
                ->action(function (InventoryConditionChange $record, array $data): void {
                    $paths = is_array($data['evidence'] ?? null)
                        ? array_values(array_filter($data['evidence'], is_string(...)))
                        : [];

                    app(DisposalEvidenceSynchronizer::class)->sync($record, $paths);

                    Notification::make()
                        ->success()
                        ->title(__('admin.inventory.condition_change.notifications.evidence_attached'))
                        ->send();
                }),
            Action::make('post')
                ->label(__('admin.inventory.condition_change.actions.post'))
                ->color('success')
                ->visible(fn (InventoryConditionChange $record): bool => $record->isDraft()
                    && (auth()->user()?->can('post', $record) ?? false))
                ->authorize(fn (InventoryConditionChange $record): bool => auth()->user()?->can('post', $record) ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.inventory.condition_change.post_impact'))
                ->action(fn (InventoryConditionChange $record) => $this->runConditionChangeAction(
                    fn (InventoryConditionChangeService $service, User $actor): InventoryConditionChange => $service->post($record, $actor),
                    __('admin.inventory.condition_change.notifications.posted'),
                )),
            Action::make('cancel')
                ->label(__('admin.inventory.condition_change.actions.cancel'))
                ->color('danger')
                ->visible(fn (InventoryConditionChange $record): bool => $record->isDraft()
                    && (auth()->user()?->can('cancel', $record) ?? false))
                ->authorize(fn (InventoryConditionChange $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                ->schema([
                    Textarea::make('reason')->label(__('admin.inventory.count_ui.fields.reason'))->required()->maxLength(2_000),
                ])
                ->action(function (InventoryConditionChange $record, array $data): void {
                    $reason = $data['reason'] ?? null;

                    if (! is_string($reason)) {
                        throw new LogicException('A cancellation reason is required.');
                    }

                    $this->runConditionChangeAction(
                        fn (InventoryConditionChangeService $service, User $actor): InventoryConditionChange => $service->cancel($record, $actor, $reason),
                        __('admin.inventory.condition_change.notifications.cancelled'),
                    );
                }),
        ];
    }

    private function runConditionChangeAction(callable $operation, string $successMessage): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated inventory condition-change actor is required.');
        }

        $this->runInventoryOperation(
            fn (): mixed => $operation(app(InventoryConditionChangeService::class), $actor),
            $successMessage,
        );
    }
}
