<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\CustomerProfileChangeRequestStatus;
use App\Models\CustomerProfile;
use App\Models\CustomerProfileChangeRequest;
use App\Models\User;
use App\Services\Crm\CustomerProfileChangeRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Reviews legal/company identity change proposals — approving here is the
 * only way {@see CustomerProfileChangeRequestService::approve()} ever
 * applies a change to the underlying CustomerProfile.
 */
final class CustomerProfileChangeRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'changeRequests';

    protected static ?string $title = 'Legal / Company Change Requests';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('requested_changes')
                    ->label('Requested changes')
                    ->state(static function (CustomerProfileChangeRequest $record): string {
                        $changes = $record->requested_changes;

                        if ($changes === []) {
                            return '—';
                        }

                        return collect($changes)
                            ->map(static fn (mixed $value, int|string $key): string => sprintf('%s: %s', $key, is_scalar($value) ? (string) $value : json_encode($value)))
                            ->implode(', ');
                    })
                    ->wrap(),
                TextColumn::make('reason')->placeholder('—')->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CustomerProfileChangeRequestStatus $state): string => $state->label())
                    ->color(fn (CustomerProfileChangeRequestStatus $state): string => $state->color()),
                TextColumn::make('reviewedBy.name')->label('Reviewed by')->placeholder('—'),
                TextColumn::make('review_note')->label('Review note')->placeholder('—')->wrap(),
                TextColumn::make('created_at')->label('Submitted')->dateTime(),
            ])
            ->recordActions([
                $this->approveAction(),
                $this->rejectAction(),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }

    private function approveAction(): Action
    {
        return Action::make('approveChangeRequest')
            ->label('Approve')
            ->icon(Heroicon::CheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('This will apply the requested changes to the customer profile immediately.')
            ->schema([
                Textarea::make('note')->label('Note')->rows(2)->maxLength(1000),
            ])
            ->visible(fn (CustomerProfileChangeRequest $record): bool => $record->isPending())
            ->authorize(fn (): bool => $this->actor()?->can('review', $this->ownerCustomer()) ?? false)
            ->action(function (CustomerProfileChangeRequest $record, array $data): void {
                $actor = $this->actor();

                if (! $actor instanceof User) {
                    return;
                }

                $note = $data['note'] ?? null;

                app(CustomerProfileChangeRequestService::class)->approve(
                    $actor,
                    $record,
                    is_string($note) && $note !== '' ? $note : null,
                );

                Notification::make()->success()->title('Change request approved')->send();
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('rejectChangeRequest')
            ->label('Reject')
            ->icon(Heroicon::XCircle)
            ->color('danger')
            ->schema([
                Textarea::make('note')->label('Reason')->rows(2)->required()->maxLength(1000),
            ])
            ->visible(fn (CustomerProfileChangeRequest $record): bool => $record->isPending())
            ->authorize(fn (): bool => $this->actor()?->can('review', $this->ownerCustomer()) ?? false)
            ->action(function (CustomerProfileChangeRequest $record, array $data): void {
                $actor = $this->actor();

                if (! $actor instanceof User) {
                    return;
                }

                $note = $data['note'] ?? null;

                app(CustomerProfileChangeRequestService::class)->reject(
                    $actor,
                    $record,
                    is_string($note) ? $note : '',
                );

                Notification::make()->danger()->title('Change request rejected')->send();
            });
    }

    private function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function ownerCustomer(): CustomerProfile
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof CustomerProfile) {
            throw new LogicException('Expected a CustomerProfile owner record.');
        }

        return $owner;
    }
}
