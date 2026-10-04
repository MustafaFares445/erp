<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Actions;

use App\Enums\SupportPermission;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyRecoveryStatus;
use App\Filament\Support\CurrencySelect;
use App\Models\MaintenanceRecord;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarrantyRecoveryClaim;
use App\Services\Support\WarrantyClaimService;
use App\Services\Support\WarrantyRecoveryService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use LogicException;

final class WarrantyRecoveryActions
{
    /** @return list<Action> */
    public static function make(): array
    {
        return [
            self::createClaim(),
            self::submitClaim(),
            self::recordDecision(),
            self::recordReceipt(),
        ];
    }

    private static function createClaim(): Action
    {
        return Action::make('createWarrantyRecovery')
            ->label(__('Create Recovery Claim'))
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->authorize(fn (): bool => self::canManage())
            ->visible(static fn (MaintenanceRecord $record): bool => $record->coverage_decision === WarrantyClaimDecision::ThirdPartyWarranty
                && ! $record->warrantyRecoveryClaim()->exists())
            ->fillForm(static function (MaintenanceRecord $record): array {
                $summary = app(WarrantyClaimService::class)->coverageSummary($record);

                return [
                    'claimed_amount' => round($summary['covered_amount_minor'] / 100, 2),
                    'currency' => 'AED',
                ];
            })
            ->schema([
                Section::make(__('Third-party recovery'))
                    ->description(__('Track reimbursement from the manufacturer or supplier separately from customer billing.'))
                    ->columns(2)
                    ->schema([
                        Select::make('supplier_id')
                            ->label(__('Responsible supplier'))
                            ->options(static fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->required(static fn (MaintenanceRecord $record): bool => $record->coverage_source === WarrantyCoverageSource::SupplierWarranty)
                            ->visible(static fn (MaintenanceRecord $record): bool => $record->coverage_source === WarrantyCoverageSource::SupplierWarranty),
                        TextInput::make('counterparty_name')
                            ->label(__('Manufacturer / warranty provider'))
                            ->required(static fn (MaintenanceRecord $record): bool => $record->coverage_source === WarrantyCoverageSource::ManufacturerWarranty)
                            ->visible(static fn (MaintenanceRecord $record): bool => $record->coverage_source === WarrantyCoverageSource::ManufacturerWarranty),
                        TextInput::make('external_reference')
                            ->label(__('External claim reference')),
                        TextInput::make('claimed_amount')
                            ->label(__('Amount to recover'))
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->required(),
                        CurrencySelect::make('currency')->required(),
                        Textarea::make('notes')->rows(3)->columnSpanFull(),
                    ]),
            ])
            ->action(static function (MaintenanceRecord $record, array $data): void {
                try {
                    $data = self::stringKeyedData($data);
                    $amount = $data['claimed_amount'] ?? null;
                    $data['claimed_amount_minor'] = is_numeric($amount)
                        ? (int) round((float) $amount * 100)
                        : 0;

                    app(WarrantyRecoveryService::class)->create($record, $data, self::actor());
                    Notification::make()->success()->title(__('Recovery claim created'))->body(__('Submit it when the external claim has been sent.'))->send();
                } catch (ValidationException|DomainException $exception) {
                    Notification::make()->danger()->title(__('Unable to create recovery claim'))->body(__($exception->getMessage()))->send();
                }
            });
    }

    private static function submitClaim(): Action
    {
        return Action::make('submitWarrantyRecovery')
            ->label(__('Submit Recovery Claim'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->authorize(fn (): bool => self::canManage())
            ->visible(static fn (MaintenanceRecord $record): bool => self::claim($record)?->status === WarrantyRecoveryStatus::Draft)
            ->schema([
                TextInput::make('external_reference')
                    ->label(__('External claim reference'))
                    ->helperText(__('Optional if the provider has not supplied a reference yet.')),
            ])
            ->action(static function (MaintenanceRecord $record, array $data): void {
                $claim = self::claim($record);

                if (! $claim instanceof WarrantyRecoveryClaim) {
                    return;
                }

                try {
                    app(WarrantyRecoveryService::class)->submit(
                        $claim,
                        self::actor(),
                        is_string($data['external_reference'] ?? null) ? $data['external_reference'] : null,
                    );
                    Notification::make()->success()->title(__('Recovery claim submitted'))->send();
                } catch (ValidationException|DomainException $exception) {
                    Notification::make()->danger()->title(__('Unable to submit recovery claim'))->body(__($exception->getMessage()))->send();
                }
            });
    }

    private static function recordDecision(): Action
    {
        return Action::make('decideWarrantyRecovery')
            ->label(__('Record Recovery Decision'))
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->authorize(fn (): bool => self::canManage())
            ->visible(static fn (MaintenanceRecord $record): bool => self::claim($record)?->status === WarrantyRecoveryStatus::Submitted)
            ->schema([
                Select::make('decision')
                    ->options(['approved' => __('Approved'), 'rejected' => __('Rejected')])
                    ->required()
                    ->live()
                    ->native(false),
                TextInput::make('approved_amount')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required(static fn (Get $get): bool => $get('decision') === 'approved')
                    ->visible(static fn (Get $get): bool => $get('decision') === 'approved'),
                Textarea::make('rejection_reason')
                    ->required(static fn (Get $get): bool => $get('decision') === 'rejected')
                    ->visible(static fn (Get $get): bool => $get('decision') === 'rejected'),
            ])
            ->action(static function (MaintenanceRecord $record, array $data): void {
                $claim = self::claim($record);
                if (! $claim instanceof WarrantyRecoveryClaim) {
                    return;
                }

                try {
                    if (($data['decision'] ?? null) === 'approved') {
                        $amount = $data['approved_amount'] ?? null;
                        $minor = is_numeric($amount) ? (int) round((float) $amount * 100) : 0;
                        app(WarrantyRecoveryService::class)->approve($claim, $minor, self::actor());
                    } else {
                        app(WarrantyRecoveryService::class)->reject(
                            $claim,
                            is_string($data['rejection_reason'] ?? null) ? $data['rejection_reason'] : '',
                            self::actor(),
                        );
                    }

                    Notification::make()->success()->title(__('Recovery decision recorded'))->send();
                } catch (ValidationException|DomainException $exception) {
                    Notification::make()->danger()->title(__('Unable to record recovery decision'))->body(__($exception->getMessage()))->send();
                }
            });
    }

    private static function recordReceipt(): Action
    {
        return Action::make('recordWarrantyRecoveryReceipt')
            ->label(__('Record Recovery Receipt'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->authorize(fn (): bool => self::canManage())
            ->visible(static fn (MaintenanceRecord $record): bool => in_array(
                self::claim($record)?->status,
                [WarrantyRecoveryStatus::Approved, WarrantyRecoveryStatus::PartiallyReceived],
                true,
            ))
            ->fillForm(static fn (MaintenanceRecord $record): array => [
                'received_amount' => ($claim = self::claim($record)) instanceof WarrantyRecoveryClaim
                    ? round($claim->outstandingMinor() / 100, 2)
                    : null,
            ])
            ->schema([
                TextInput::make('received_amount')
                    ->label(__('Amount received'))
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required(),
            ])
            ->action(static function (MaintenanceRecord $record, array $data): void {
                $claim = self::claim($record);
                if (! $claim instanceof WarrantyRecoveryClaim) {
                    return;
                }

                try {
                    $amount = $data['received_amount'] ?? null;
                    $minor = is_numeric($amount) ? (int) round((float) $amount * 100) : 0;
                    app(WarrantyRecoveryService::class)->recordReceipt($claim, $minor, self::actor());
                    Notification::make()->success()->title(__('Recovery receipt recorded'))->send();
                } catch (ValidationException|DomainException $exception) {
                    Notification::make()->danger()->title(__('Unable to record recovery receipt'))->body(__($exception->getMessage()))->send();
                }
            });
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private static function stringKeyedData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private static function claim(MaintenanceRecord $record): ?WarrantyRecoveryClaim
    {
        $claim = $record->warrantyRecoveryClaim()->first();

        return $claim instanceof WarrantyRecoveryClaim ? $claim : null;
    }

    private static function canManage(): bool
    {
        return auth()->user()?->can(SupportPermission::WarrantyRecoveryManage->value) ?? false;
    }

    private static function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        return $actor;
    }
}
