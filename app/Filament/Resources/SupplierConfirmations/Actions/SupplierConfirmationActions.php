<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierConfirmations\Actions;

use App\Enums\SupplierConfirmationStatus;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Services\Purchasing\SupplierConfirmationService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;

/**
 * The two ways a pending confirmation can be answered, defined once and mounted
 * on both the standalone resource and the purchase order's relation manager.
 *
 * Both disappear the moment the record is answered — not because the permission
 * changes, but because an answered confirmation accepts no further writes at
 * all (FR-031).
 */
final class SupplierConfirmationActions
{
    use InteractsWithPurchasingServices;

    public static function confirm(): Action
    {
        return Action::make('confirm')
            ->label(__('admin.purchasing.confirmation_status.confirmed'))
            ->icon(Heroicon::CheckCircle)
            ->color('success')
            ->schema([
                DatePicker::make('promised_at')
                    ->label(__('admin.purchasing.fields.promised_at'))
                    ->required(),
                Textarea::make('notes')
                    ->label(__('admin.purchasing.fields.notes'))
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->visible(fn (SupplierConfirmation $record): bool => self::canAnswer($record))
            ->authorize(fn (SupplierConfirmation $record): bool => self::canAnswer($record))
            ->action(function (SupplierConfirmation $record, array $data): void {
                /** @var array<string, mixed> $data */
                self::answer($record, SupplierConfirmationStatus::Confirmed, $data);
            });
    }

    public static function reject(): Action
    {
        return Action::make('rejectConfirmation')
            ->label(__('admin.purchasing.confirmation_status.rejected'))
            ->icon(Heroicon::XCircle)
            ->color('danger')
            ->schema([
                Textarea::make('notes')
                    ->label(__('admin.purchasing.fields.notes'))
                    ->rows(2)
                    ->required()
                    ->maxLength(1000),
            ])
            ->visible(fn (SupplierConfirmation $record): bool => self::canAnswer($record))
            ->authorize(fn (SupplierConfirmation $record): bool => self::canAnswer($record))
            ->action(function (SupplierConfirmation $record, array $data): void {
                /** @var array<string, mixed> $data */
                self::answer($record, SupplierConfirmationStatus::Rejected, $data);
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function answer(SupplierConfirmation $record, SupplierConfirmationStatus $outcome, array $data): void
    {
        $actor = self::purchasingActor();

        if (! $actor instanceof User) {
            return;
        }

        $promised = self::nullableStringFrom($data['promised_at'] ?? null);

        self::runPurchasingOperation(
            fn (): SupplierConfirmation => app(SupplierConfirmationService::class)->answer(
                $actor,
                $record,
                $outcome,
                $promised === null ? null : CarbonImmutable::parse($promised),
                self::nullableStringFrom($data['notes'] ?? null),
            ),
            'admin.purchasing.notifications.confirmation_recorded',
        );
    }

    private static function canAnswer(SupplierConfirmation $confirmation): bool
    {
        return self::purchasingActor()?->can('answer', $confirmation) ?? false;
    }
}
