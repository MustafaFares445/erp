<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Actions;

use App\Enums\CustomerApprovalStatus;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Crm\CustomerApprovalService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * The customer review lifecycle actions, thin adapters over
 * {@see CustomerApprovalService}, mounted on both the customers table and
 * the customer view page.
 */
final class CustomerApprovalActions
{
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label(__('admin.crm.actions.approve'))
            ->icon(Heroicon::CheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->schema([
                Textarea::make('note')->label('Note')->rows(2)->maxLength(1000),
            ])
            ->visible(fn (CustomerProfile $record): bool => in_array($record->approval_status, [
                CustomerApprovalStatus::Pending,
                CustomerApprovalStatus::ChangesRequested,
            ], true))
            ->authorize('review')
            ->action(function (CustomerProfile $record, array $data): void {
                $actor = self::actor();

                if (! $actor instanceof User) {
                    return;
                }

                app(CustomerApprovalService::class)->approve($actor, $record, self::note($data));

                Notification::make()->success()->title('Customer approved')->send();
            });
    }

    public static function requestChanges(): Action
    {
        return Action::make('requestChanges')
            ->label(__('admin.crm.actions.request_changes'))
            ->icon(Heroicon::PencilSquare)
            ->color('warning')
            ->schema([
                Textarea::make('note')->label('What needs to change')->rows(2)->required()->maxLength(1000),
            ])
            ->visible(fn (CustomerProfile $record): bool => in_array($record->approval_status, [
                CustomerApprovalStatus::Pending,
                CustomerApprovalStatus::Approved,
            ], true))
            ->authorize('review')
            ->action(function (CustomerProfile $record, array $data): void {
                $actor = self::actor();

                if (! $actor instanceof User) {
                    return;
                }

                app(CustomerApprovalService::class)->requestChanges($actor, $record, self::note($data) ?? '');

                Notification::make()->warning()->title('Changes requested')->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('rejectApproval')
            ->label(__('admin.crm.actions.reject'))
            ->icon(Heroicon::XCircle)
            ->color('danger')
            ->schema([
                Textarea::make('note')->label('Reason')->rows(2)->required()->maxLength(1000),
            ])
            ->visible(fn (CustomerProfile $record): bool => in_array($record->approval_status, [
                CustomerApprovalStatus::Pending,
                CustomerApprovalStatus::ChangesRequested,
                CustomerApprovalStatus::Approved,
            ], true))
            ->authorize('review')
            ->action(function (CustomerProfile $record, array $data): void {
                $actor = self::actor();

                if (! $actor instanceof User) {
                    return;
                }

                app(CustomerApprovalService::class)->reject($actor, $record, self::note($data) ?? '');

                Notification::make()->danger()->title('Customer rejected')->send();
            });
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->label(__('admin.crm.actions.reactivate'))
            ->icon(Heroicon::ArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->schema([
                Textarea::make('note')->label('Note')->rows(2)->maxLength(1000),
            ])
            ->visible(fn (CustomerProfile $record): bool => $record->approval_status === CustomerApprovalStatus::Rejected)
            ->authorize('review')
            ->action(function (CustomerProfile $record, array $data): void {
                $actor = self::actor();

                if (! $actor instanceof User) {
                    return;
                }

                app(CustomerApprovalService::class)->reactivate($actor, $record, self::note($data));

                Notification::make()->success()->title('Customer moved back to Pending')->send();
            });
    }

    private static function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /** @param array<array-key, mixed> $data */
    private static function note(array $data): ?string
    {
        $note = $data['note'] ?? null;

        return is_string($note) && $note !== '' ? $note : null;
    }
}
